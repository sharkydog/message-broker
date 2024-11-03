<?php
namespace SharkyDog\MessageBroker;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;

class Server {
  use PrivateEmitterTrait;

  private $_peer;
  private $_peers = [];
  private $_subscriptions = [];
  private $_wildcards = [];
  private $_notifications = [];
  private $_notifications_all = [];
  private $_last_unset_pcre;

  public function __construct(string $name='broker') {
    $this->_peer = new Peer($name, null);
  }

  public static function isWildcard(string $topic): bool {
    return (bool)preg_match('/[\*\+]/',$topic);
  }

  public static function expandWildcard(string $topic): string {
    $pcre = preg_replace('/[\/\.\-]/','\\\$0',$topic);
    $pcre = str_replace(['+','*'], ['[^\/]+','.+'], $pcre);
    $pcre = '/^'.$pcre.'$/';
    return $pcre;
  }

  public function addPeer(Peer $peer) {
    $this->_peers[] = $peer;
    $pid = array_key_last($this->_peers);

    $peer->on('close', function() use($pid) {
      $this->_onPeerClose($pid);
    });

    $peer->on('message', function($topic, $msg, $name) use($pid) {
      $this->_onPeerMessage($pid, $topic, $msg, $name);
    });

    Log::debug('MSGB: New peer (total: '.count($this->_peers).'): '.get_class($peer).': '.$peer->name, 'msgb','peer');
    $this->_emit('peer', [$peer]);
  }

  private function _onPeerClose($pid) {
    Log::debug('MSGB: Close peer (total: '.count($this->_peers).'): '.$this->_peers[$pid]->name, 'msgb','peer');
    unset($this->_peers[$pid]);
    $this->_clearSubscriptions($pid);
    $this->_clearNotifications($pid);
  }

  private function _onPeerMessage($pid, $topic, $msg, $name) {
    $peer = $this->_peers[$pid];

    if(strpos($topic,'broker/') === 0) {
      $this->_onBrokerCommand($pid, substr($topic,7), $msg);
      return;
    }

    if(preg_match('/[^a-z0-9\/\.\-]/i',$topic)) {
      return;
    }

    $sent = [];

    if(isset($this->_subscriptions[$topic])) {
      foreach($this->_subscriptions[$topic] as $spid => $speer) {
        if($spid == $pid) continue;
        $sent[$spid] = true;
        $speer->sendToPeer($topic, $msg, $peer, $name);
      }
    }

    foreach($this->_wildcards as $wtopic => &$wildc) {
      if(!preg_match($wildc['pcre'],$topic)) {
        continue;
      }

      foreach($wildc['peers'] as $spid => $speer) {
        if($spid == $pid || isset($sent[$spid])) {
          continue;
        }

        $sent[$spid] = true;
        $speer->sendToPeer($topic, $msg, $peer, $name);
      }
    }
  }

  private function _onBrokerCommand($pid, $cmd, $msg) {
    if(!$cmd) return;
    $peer = $this->_peers[$pid];

    if($cmd == 'subscribe') {
      if(!$msg) return;
      $topics = preg_split('/[\s,]+/', $msg, -1, PREG_SPLIT_NO_EMPTY);
      if(empty($topics)) return;

      foreach($topics as $topic) {
        if(strpos($topic,'broker/') === 0) {
          continue;
        }
        if(preg_match('/[^a-z0-9\/\.\-\*\+]/i',$topic)) {
          continue;
        }
        if($this->_set_pid($this->_subscriptions,$topic,$pid,$peer)) {
          $this->_notifySubscription($topic, $pid);
        }
      }

      return;
    }

    if($cmd == 'subscribe/clear') {
      $this->_clearSubscriptions($pid);
      return;
    }

    if($cmd == 'unsubscribe') {
      if(!$msg) return;
      $topics = preg_split('/[\s,]+/', $msg, -1, PREG_SPLIT_NO_EMPTY);
      if(empty($topics)) return;

      foreach($topics as $topic) {
        if(strpos($topic,'broker/') === 0) {
          continue;
        }
        if(preg_match('/[^a-z0-9\/\.\-\*\+]/i',$topic)) {
          continue;
        }
        if($this->_unset_pid($this->_subscriptions,$topic,$pid)) {
          $this->_notifySubscription($topic, $pid);
        }
      }

      return;
    }

    if($cmd == 'subscribers') {
      if(!$msg) return;
      $topics = preg_split('/[\s,]+/', $msg, -1, PREG_SPLIT_NO_EMPTY);
      if(empty($topics)) return;

      foreach($topics as $topic) {
        if(strpos($topic,'broker/') === 0) {
          continue;
        }
        if(preg_match('/[^a-z0-9\/\.\-]/i',$topic)) {
          continue;
        }
        if($this->_set_pid($this->_notifications,$topic,$pid,$peer)) {
          $this->_notifySubscribers($topic, $peer, $pid);
        }
      }

      return;
    }

    if($cmd == 'subscribers/all') {
      if(isset($this->_notifications_all[$pid])) {
        return;
      }

      $this->_notifications_all[$pid] = $peer;

      foreach($this->_subscriptions as $topic => $peers) {
        $this->_sendSubscribersAll($topic, $peer, $pid, $peers, false);
      }

      foreach($this->_wildcards as $topic => &$wildc) {
        $peers = $wildc['peers'] ?? [];
        $this->_sendSubscribersAll($topic, $peer, $pid, $peers, false);
      }

      return;
    }

    if($cmd == 'subscribers/clear') {
      $this->_clearNotifications($pid);
      return;
    }
  }

  private function _clearSubscriptions($pid) {
    foreach($this->_subscriptions as $topic => &$peers) {
      if($this->_unset_pid($this->_subscriptions,$topic,$pid)) {
        $this->_notifySubscription($topic);
      }
    }
    foreach($this->_wildcards as $topic => &$wildc) {
      if($this->_unset_wildcard($topic,$pid)) {
        $this->_notifySubscription($topic);
      }
    }
  }

  private function _clearNotifications($pid) {
    foreach($this->_notifications as $topic => &$peers) {
      $this->_unset_pid($this->_notifications,$topic,$pid);
    }
    unset($this->_notifications_all[$pid]);
  }

  private function _set_pid(&$store,$topic,$pid,$peer) {
    if(self::isWildcard($topic)) {
      return $this->_set_wildcard($topic,$pid,$peer);
    }

    if(isset($store[$topic][$pid])) {
      return false;
    }
    if(!isset($store[$topic])) {
      $store[$topic] = [];
    }

    $store[$topic][$pid] = $peer;

    return true;
  }

  private function _unset_pid(&$store,$topic,$pid) {
    if(self::isWildcard($topic)) {
      return $this->_unset_wildcard($topic,$pid);
    }

    if(!isset($store[$topic][$pid])) {
      return false;
    }

    unset($store[$topic][$pid]);

    if(empty($store[$topic])) {
      unset($store[$topic]);
    }

    return true;
  }

  private function _set_wildcard($topic,$pid,$peer) {
    if(isset($this->_wildcards[$topic]['peers'][$pid])) {
      return false;
    }

    if(!isset($this->_wildcards[$topic])) {
      $this->_wildcards[$topic] = [
        'pcre'  => self::expandWildcard($topic),
        'peers' => []
      ];
    }

    $this->_wildcards[$topic]['peers'][$pid] = $peer;

    return true;
  }

  private function _unset_wildcard($topic,$pid) {
    if(!isset($this->_wildcards[$topic]['peers'][$pid])) {
      return false;
    }

    unset($this->_wildcards[$topic]['peers'][$pid]);

    if(empty($this->_wildcards[$topic]['peers'])) {
      $this->_last_unset_pcre = $this->_wildcards[$topic]['pcre'];
      unset($this->_wildcards[$topic]);
    }

    return true;
  }

  private function _notifySubscription($stopic, $spid=null) {
    if(!($spcre = $this->_wildcards[$stopic]['pcre'] ?? null) && $this->_last_unset_pcre) {
      $spcre = $this->_last_unset_pcre;
      $this->_last_unset_pcre = null;
    }

    if(!empty($this->_notifications_all)) {
      if($spcre) {
        $speers = $this->_wildcards[$stopic]['peers'] ?? [];
      } else {
        $speers = $this->_subscriptions[$stopic] ?? [];
      }

      foreach($this->_notifications_all as $npid => $npeer) {
        if($spid !== null && $spid === $npid) {
          continue;
        }
        $this->_sendSubscribersAll($stopic, $npeer, $npid, $speers, true);
      }
    }

    if($spcre) {
      $notifications = &$this->_notifications;
    } else if(isset($this->_notifications[$stopic])) {
      $notifications = [$stopic => &$this->_notifications[$stopic]];
    } else {
      return;
    }

    foreach($notifications as $ntopic => &$npeers) {
      if($spcre && !preg_match($spcre,$ntopic)) {
        continue;
      }

      $speers = $this->_subscriptions[$ntopic] ?? [];

      foreach($this->_wildcards as $wtopic => &$wildc) {
        if(!preg_match($wildc['pcre'],$ntopic)) {
          continue;
        }
        $speers += $wildc['peers'];
      }

      foreach($npeers as $npid => $npeer) {
        if($spid !== null && $spid === $npid) {
          continue;
        }
        $this->_sendSubscribers($ntopic, $npeer, $npid, $speers, true);
      }
    }
  }

  private function _notifySubscribers($ntopic, $npeer, $npid) {
    $speers = $this->_subscriptions[$ntopic] ?? [];

    foreach($this->_wildcards as $wtopic => &$wildc) {
      if(!preg_match($wildc['pcre'],$ntopic)) {
        continue;
      }
      $speers += $wildc['peers'];
    }

    $this->_sendSubscribers($ntopic, $npeer, $npid, $speers, false);
  }

  private function _sendSubscribers($ntopic, $npeer, $npid, $speers, $sendZero, $all=false) {
    unset($speers[$npid]);

    if(!($count = count($speers)) && !$sendZero) {
      return;
    }

    $npeer->sendToPeer(
      $all ? 'broker/subscribers/all' : 'broker/subscribers',
      $ntopic.' '.$count,
      $this->_peer
    );
  }

  private function _sendSubscribersAll($ntopic, $npeer, $npid, $speers, $sendZero) {
    $this->_sendSubscribers($ntopic, $npeer, $npid, $speers, $sendZero, true);
  }
}
