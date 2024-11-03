<?php
namespace SharkyDog\MessageBroker;
use SharkyDog\HTTP;
use React\EventLoop\Loop;

class Service {
  private $_allowNoListeners = true;
  private $_msgb;
  private $_http;
  private $_wsh;
  private $_tcp;
  private $_br;
  private $_brc = [];

  final public function __construct() {
    Loop::futureTick(function() {
      $this->_onStart();
    });
  }

  private function _onStart() {
    $listenCntAll = 0;

    if($this->_http) {
      if(!($listenCnt = $this->_http->getListenCount())) {
        throw new \Exception('Message broker: HTTP defined, but not listening');
      }
      $listenCntAll += $listenCnt;
    }

    if($this->_tcp) {
      if(!($listenCnt = $this->_tcp->getListenCount())) {
        throw new \Exception('Message broker: TCP defined, but not listening');
      }
      $listenCntAll += $listenCnt;
    }

    if(!$listenCntAll && !$this->_allowNoListeners) {
      throw new \Exception('Message broker: Not listening on any interface');
    }

    if($this->_wsh) {
      if(!count($this->_http->getHandlerRoutes($this->_wsh))) {
        throw new \Exception('Message broker: No HTTP routes defined for WS');
      }
    }

    $this->MSGB();

    foreach($this->_brc as $brc) {
      $brc->connect();
    }
  }

  private function _bridge($key, $brc) {
    if($key == '_brc_local_') {
      throw new \Exception('Message broker: Bridge: Key "_brc_local_" is not allowed');
    }

    if(isset($this->_brc[$key])) {
      return;
    }

    if(!$brc) {
      throw new \Exception('Message broker: Bridge: '.$key.': Client not set');
    }
    else if(is_string($brc)) {
      $url = parse_url($brc);

      if(!isset($url['scheme'],$url['host'])) {
        throw new \Exception('Message broker: Bridge: '.$key.': Can not parse url');
      }

      $scheme = strtolower($url['scheme']);

      if($scheme == 'tcp') {
        if(!isset($url['port'])) {
          throw new \Exception('Message broker: Bridge: '.$key.': Port is required for TCP url');
        }
        $brc = new TCP\Client($url['host'], $url['port']);
      }
      else if($scheme == 'ws' || $scheme == 'wss') {
        $brc = new WebSocket\Client($brc);
      }
      else {
        throw new \Exception('Message broker: Bridge: '.$key.': Unknown url scheme, supported are tcp, ws and wss');
      }
    }
    else if(!($brc instanceOf ClientInterface)) {
      throw new \Exception('Message broker: Bridge: '.$key.': Client must be an url or instance of '.ClientInterface::class);
    }

    if(!$this->_br) {
      $this->_br = new Bridge;
      $this->_br->broker('_brc_local_', new Local\Client('_brc_local_', $this->MSGB()));
    }

    $this->_br->broker($key, $brc);
    $this->_brc[$key] = $brc;
  }

  public function AllowNoListeners(bool $p=true) {
    $this->_allowNoListeners = $p;
  }

  public function MSGB(?Server $msgb=null): Server {
    if($this->_msgb) {
      return $this->_msgb;
    }

    $this->_msgb = $msgb ?: new Server;

    return $this->_msgb;
  }

  public function HTTP(?HTTP\Server $http=null): HTTP\Server {
    if($this->_http) {
      return $this->_http;
    }

    $this->_http = $http ?: new HTTP\Server;

    return $this->_http;
  }

  public function WS(?WebSocket\Handler $wsh=null): WebSocket\Handler {
    if($this->_wsh) {
      return $this->_wsh;
    }

    $this->HTTP();
    $this->_wsh = $wsh ?: new WebSocket\Handler;
    $this->_wsh->on('peer', function(Peer $peer) {
      $this->MSGB()->addPeer($peer);
    });

    return $this->_wsh;
  }

  public function TCP(?TCP\Server $tcp=null): TCP\Server {
    if($this->_tcp) {
      return $this->_tcp;
    }

    $this->_tcp = $tcp ?: new TCP\Server;
    $this->_tcp->on('peer', function(Peer $peer) {
      $this->MSGB()->addPeer($peer);
    });

    return $this->_tcp;
  }

  public function WSListen(string $addr, int $port, $tls=null) {
    $this->WS();
    $this->HTTP()->listen($addr, $port, $tls);
  }

  public function WSRoute(string $route) {
    $this->HTTP()->route($route, $this->WS());
  }

  public function TCPListen(string $addr, int $port) {
    $this->TCP()->listen($addr, $port);
  }

  public function Bridge(string $key, $brokerClient=null): ?ClientInterface {
    if($brokerClient !== null) {
      $this->_bridge($key, $brokerClient);
    }
    return $this->_brc[$key] ?? null;
  }
}
