<?php
namespace SharkyDog\MessageBroker;
use React\EventLoop\Loop;

class Bridge {
  private $_brokers = [];
  private $_subscribers = [];
  private $_pcre = [];

  public function __construct() {
  }

  public function broker(string $key, ClientInterface $msgb) {
    if(isset($this->_brokers[$key])) {
      return;
    }

    $this->_brokers[$key] = $msgb;

    $msgb->on('message', function($topic,$msg,$from) use($key) {
      $this->_onBrokerMsg($key,$topic,$msg,$from);
    });

    if($msgb instanceOf Local\Client) {
      Loop::futureTick(function() use($key) {
        $this->_onBrokerOpen($key);
      });
    } else {
      $msgb->on('open', function() use($key) {
        $this->_onBrokerOpen($key);
      });
      $msgb->on('close', function() use($key) {
        $this->_onBrokerClose($key);
      });
    }
  }

  private function _onBrokerOpen($key) {
    $msgb = $this->_brokers[$key];

    $msgb->send('broker/subscribers/all','');

    foreach($this->_subscribers as $topic => $fromKeys) {
      if(!isset($this->_subscribers[$topic][$key])) {
        continue;
      }
      $msgb->send('broker/subscribe', $topic);
    }
  }

  private function _onBrokerClose($key) {
    foreach($this->_subscribers as $topic => $fromKeys) {
      foreach($fromKeys as $fromKey => $toKeys) {
        unset($this->_subscribers[$topic][$fromKey][$key]);

        if(empty($this->_subscribers[$topic][$fromKey])) {
          unset($this->_subscribers[$topic][$fromKey]);
          $msgb = $this->_brokers[$fromKey];
          $msgb->send('broker/unsubscribe', $topic);
        }

        if(empty($this->_subscribers[$topic])) {
          unset($this->_subscribers[$topic],$this->_pcre[$topic]);
        }
      }
    }
  }

  private function _onBrokerMsg($key, $topic, $msg, $from) {
    if($topic == 'broker/subscribers/all') {
      list($topic,$count) = explode(' ',$msg);
      $count = (int)$count;

      foreach($this->_brokers as $fromKey => $msgb) {
        if($fromKey == $key) {
          continue;
        }

        $subscribe = false;
        $unsubscribe = false;

        if($count) {
          if(!isset($this->_subscribers[$topic])) {
            $this->_subscribers[$topic] = [];
            if(Server::isWildcard($topic)) {
              $this->_pcre[$topic] = Server::expandWildcard($topic);
            }
          }

          if(!isset($this->_subscribers[$topic][$fromKey])) {
            $this->_subscribers[$topic][$fromKey] = [];
            $subscribe = true;
          }

          $this->_subscribers[$topic][$fromKey][$key] = $count;
        }
        else if(isset($this->_subscribers[$topic][$fromKey][$key])) {
          unset($this->_subscribers[$topic][$fromKey][$key]);

          if(empty($this->_subscribers[$topic][$fromKey])) {
            unset($this->_subscribers[$topic][$fromKey]);
            $unsubscribe = true;
          }

          if(empty($this->_subscribers[$topic])) {
            unset($this->_subscribers[$topic],$this->_pcre[$topic]);
          }
        }

        if(!$subscribe && !$unsubscribe) {
          continue;
        }

        if($subscribe) {
          $msgb->send('broker/subscribe', $topic);
        } else {
          $msgb->send('broker/unsubscribe', $topic);
        }
      }

      return;
    }

    if(!empty($this->_pcre)) {
      $subscribers = &$this->_subscribers;
    } else if(isset($this->_subscribers[$topic])) {
      $subscribers = [$topic => &$this->_subscribers[$topic]];
    } else {
      return;
    }

    $sent = [];

    foreach($subscribers as $stopic => $fromKeys) {
      if(!isset($fromKeys[$key])) {
        continue;
      }
      if(isset($this->_pcre[$stopic]) && !preg_match($this->_pcre[$stopic],$topic)) {
        continue;
      }

      foreach($fromKeys[$key] as $toKey => $count) {
        if(isset($sent[$toKey])) {
          continue;
        }

        $sent[$toKey] = true;
        $msgb = $this->_brokers[$toKey];
        $msgb->send($topic, $msg, $from);
      }
    }

    return;
  }
}
