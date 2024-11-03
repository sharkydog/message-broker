<?php
namespace SharkyDog\MessageBroker\TCP;
use Evenement\EventEmitter;

class MessageBuffer extends EventEmitter {
  private $buffer = null;
  private $sender;

  public function __construct(callable $sender=null) {
    $this->sender = $sender;
  }

  public function feed(string $data) {
    if($this->buffer === null) {
      if(($pos=strpos($data,"<")) === false) {
        $this->emit('junk', [$data]);
        return;
      }

      if($pos) {
        $junk = substr($data, 0, $pos);
        $this->emit('junk', [$junk]);
        unset($junk);
      }

      $this->buffer = '';
      $data = substr($data, $pos+1);
    }

    $this->buffer .= $data;
    $data = '';

    if(!preg_match('#(?<!\\\\)\>#', $this->buffer, $m, PREG_OFFSET_CAPTURE)) {
      return;
    }

    if(($m[0][1]+1) < strlen($this->buffer)) {
      $data = substr($this->buffer, $m[0][1]+1);
    }

    $this->buffer = substr($this->buffer, 0, $m[0][1]);

    if(strlen($this->buffer)) {
      $this->buffer = str_replace("\\>", ">", $this->buffer);
      $this->emit('data', [$this->buffer]);
    }

    $this->buffer = null;

    if(strlen($data)) {
      $this->feed($data);
    }
  }

  public function send($data) {
    if(!$this->sender) return;

    $data = str_replace(">", "\\>", $data);
    $data = "<".$data.">";

    ($this->sender)($data);
  }
}
