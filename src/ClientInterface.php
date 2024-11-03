<?php
namespace SharkyDog\MessageBroker;
use Evenement\EventEmitterInterface;

interface ClientInterface extends EventEmitterInterface {
  // event "open"
  // event "close"
  // event "message" (string $topic, string $msg, string $from)
  public function connect();
  public function connected(): bool;
  public function send(string $topic, string $msg, ?string $from=null);
}
