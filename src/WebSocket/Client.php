<?php
namespace SharkyDog\MessageBroker\WebSocket;
use SharkyDog\MessageBroker as MSGB;
use SharkyDog\HTTP\Helpers\WsClientDecorator;

class Client extends WsClientDecorator implements MSGB\ClientInterface {
  private $_name;

  public function __construct(string $url, string $name='', array $headers=[]) {
    parent::__construct($url, $headers);
    $this->_name = $name;
    $this->ws->reconnect(2);
  }

  public function on($event, callable $listener) {
    if($event == 'open' && $this->ws->connected()) $listener();
    parent::on($event, $listener);
  }
  public function once($event, callable $listener) {
    if($event == 'open' && $this->ws->connected()) $listener();
    else parent::once($event, $listener);
  }

  protected function _event_open() {
    if($this->_name) $this->send('_name', $this->_name);
    $this->_emit('open');
  }

  protected function _event_message($msg) {
    if(!($msg=json_decode($msg)) || !($msg->t??null)) return;
    $this->_emit('message', [$msg->t, $msg->m??'', $msg->n??'']);
  }

  public function connected(): bool {
    return $this->ws->connected();
  }

  public function send(string $topic, string $msg, ?string $from=null) {
    if(!$this->ws->connected()) return;
    $this->ws->send(json_encode(['t'=>$topic,'m'=>$msg,'n'=>$from?:'']));
  }
}
