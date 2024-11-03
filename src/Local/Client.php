<?php
namespace SharkyDog\MessageBroker\Local;
use SharkyDog\MessageBroker as MSGB;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;

class Client implements MSGB\ClientInterface {
  use PrivateEmitterTrait {
    PrivateEmitterTrait::on as private _PrivateEmitter_on;
    PrivateEmitterTrait::once as private _PrivateEmitter_once;
  }

  private $_peer;

  public function __construct(string $name, MSGB\Server $broker) {
    $this->_peer = new Peer($name, function($topic,$msg,$from,$name) {
      $this->_emit('message', [$topic,$msg,$name?:$from->name]);
    });
    $broker->addPeer($this->_peer);
  }

  public function on($event, callable $listener) {
    if($event == 'open') {
      $listener();
      return;
    }
    if($event == 'close') {
      return;
    }
    $this->_PrivateEmitter_on($event, $listener);
  }
  public function once($event, callable $listener) {
    if($event == 'open') {
      $listener();
      return;
    }
    if($event == 'close') {
      return;
    }
    $this->_PrivateEmitter_once($event, $listener);
  }

  public function connect() {
  }

  public function connected(): bool {
    return true;
  }

  public function send(string $topic, string $msg, ?string $from=null) {
    $this->_peer->sendToBroker($topic, $msg, $from);
  }
}
