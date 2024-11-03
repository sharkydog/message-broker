<?php
namespace SharkyDog\MessageBroker\TCP;
use SharkyDog\MessageBroker as MSGB;
use SharkyDog\MessageBroker\Log;
use SharkyDog\MessageBroker\ObjectDecorator;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\Socket\TcpServer;

class Server {
  use PrivateEmitterTrait;

  private $_sockCnt = 0;

  public function getListenCount(): int {
    return $this->_sockCnt;
  }

  public function listen(string $addr, int $port) {
    $socket = new TcpServer($addr.':'.$port);
    $socket->on('connection', function($conn) {
      $this->_onOpen($conn);
    });
    $this->_sockCnt++;
  }

  private function _onOpen($conn) {
    $cid = (int)$conn->stream;
    $url = parse_url($conn->getRemoteAddress());
    $addr = trim(($url['host']??''),'[]');
    $port = $url['port']??0;

    $conn = new ObjectDecorator($conn);
    $conn->peer = null;
    $conn->peerId = 'tcp://'.$cid.'@'.$addr.':'.$port;

    if(Log::loggerLoaded()) {
      $c = $conn->peerId;
      Log::debug('MSGB_TCP: Server: New connection '.$c, 'msgb','tcp','server');
      Log::destruct($conn->obj(), 'MSGB_TCP: Server: '.$c, 'msgb','tcp','server');
      Log::memory();
      $conn->hnd = new \SharkyDog\Log\Handle(function() use($c) {
        Log::debug('MSGB_TCP: Server: Close connection '.$c, 'msgb','tcp','server');
        Log::memory();
      });
    }

    $conn->buffer = new MessageBuffer(function($data) use($conn) {
      $conn->write($data);
    });
    $conn->buffer->on('data', function($data) use($conn) {
      $this->_onData($conn, $data);
    });

    $conn->on('data', function($data) use($conn) {
      $conn->buffer->feed($data);
    });
    $conn->on('close', function() use($conn) {
      $this->_onClose($conn);
    });
  }

  private function _onData($conn, $data) {
    if(!($data=json_decode($data)) || !($topic=(string)($data->t??''))) {
      return;
    }
    $msg = (string)($data->m ?? '');

    if(!$conn->peer) {
      if($topic == '_name' && $msg) {
        $name = $data->m;
      } else {
        $name = $conn->peerId;
      }

      $peer = new Peer(
        $name,
        function($topic,$msg,$from,$name) use($conn) {
          $conn->buffer->send(json_encode(['n'=>$name?:$from->name,'t'=>$topic,'m'=>$msg]));
        }
      );

      $conn->peer = $peer;
      $this->_emit('peer', [$peer]);
    }

    if($topic[0] == '_') {
      return;
    }

    $conn->peer->sendToBroker($topic, $msg, (string)($data->n??'')?:null);
  }

  private function _onClose($conn) {
    $conn->buffer->removeAllListeners();
    $conn->buffer = null;

    if($conn->peer) {
      $conn->peer->close();
      $conn->peer = null;
    }
  }
}
