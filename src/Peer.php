<?php
namespace SharkyDog\MessageBroker;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;

class Peer {
  use PrivateEmitterTrait;

  private $name;
  private $_sender;

  public function __construct(string $name, ?callable $sender, &$emitter=false) {
    $this->name = $name;
    $this->_sender = $sender;

    if($emitter !== false) {
      $emitter = $this->_emitter();
    }
  }

  public function __destruct() {
    Log::destruct(static::class);
  }

  public function __get($prop) {
    if($prop[0] == '_') return null;
    return $this->$prop ?? null;
  }

  public function sendToPeer(string $topic, string $msg, self $from, ?string $name=null) {
    if(!$this->_sender) return;
    ($this->_sender)($topic, $msg, $from, $name);
  }

  public function sendToBroker(string $topic, string $msg, ?string $name=null) {
    $this->_emit('message', [$topic, $msg, $name]);
  }

  public function close() {
    $this->_emit('close');
  }

  private function _event_close() {
    $this->_sender = null;
    $this->_emit('close');
    $this->removeAllListeners();
  }
}
