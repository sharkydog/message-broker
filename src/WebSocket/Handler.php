<?php
namespace SharkyDog\MessageBroker\WebSocket;
use SharkyDog\MessageBroker as MSGB;
use SharkyDog\HTTP\WebSocket as WS;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;

class Handler extends WS\Handler {
  use PrivateEmitterTrait;

  protected function wsOpen(WS\Connection $conn) {
    $conn->attr->peer = null;
    $conn->attr->peerId = 'ws://'.$conn->ID.'@'.$conn->remoteAddr.':'.$conn->remotePort;
  }

  protected function wsMsg(WS\Connection $conn, string $data) {
    if(!($data=json_decode($data)) || !($topic=(string)($data->t??''))) {
      return;
    }
    $msg = (string)($data->m ?? '');

    if(!$conn->attr->peer) {
      if($topic == '_name' && $msg) {
        $name = $data->m;
      } else {
        $name = $conn->attr->peerId;
      }

      $peer = new Peer(
        $name,
        function($topic,$msg,$from,$name) use($conn) {
          $conn->send(json_encode(['n'=>$name?:$from->name,'t'=>$topic,'m'=>$msg]));
        }
      );

      $conn->attr->peer = $peer;
      $this->_emit('peer', [$peer]);
    }

    if($topic[0] == '_') {
      return;
    }

    $conn->attr->peer->sendToBroker($topic, $msg, (string)($data->n??'')?:null);
  }

  protected function wsClose(WS\Connection $conn) {
    if(!$conn->attr->peer) return;
    $conn->attr->peer->close();
    $conn->attr->peer = null;
  }
}
