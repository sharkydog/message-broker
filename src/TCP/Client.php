<?php
namespace SharkyDog\MessageBroker\TCP;
use SharkyDog\MessageBroker as MSGB;
use SharkyDog\MessageBroker\Log;
use SharkyDog\MessageBroker\ObjectDecorator;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\Socket\Connector;
use React\EventLoop\Loop;

class Client implements MSGB\ClientInterface {
  use PrivateEmitterTrait {
    PrivateEmitterTrait::on as private _PrivateEmitter_on;
    PrivateEmitterTrait::once as private _PrivateEmitter_once;
  }

  private $_addr;
  private $_port;
  private $_name;
  private $_conn;
  private $_connPr;
  private $_connTimeout;
  private $_reconnInterval = 0;
  private $_reconnTimer;
  private $_closing = false;

  public function __construct(string $addr, int $port, string $name='') {
    $this->_addr = $addr;
    $this->_port = $port;
    $this->_name = $name;
    $this->reconnect(2);
  }

  public function on($event, callable $listener) {
    if($event == 'open' && $this->connected()) $listener();
    $this->_PrivateEmitter_on($event, $listener);
  }
  public function once($event, callable $listener) {
    if($event == 'open' && $this->connected()) $listener();
    $this->_PrivateEmitter_once($event, $listener);
  }

  public function reconnect(int $interval) {
    $this->_reconnInterval = max(0,$interval);
  }

  public function connect(int $timeout=0) {
    if($this->_conn || $this->_connPr || $this->_closing) {
      return;
    }

    if($this->_reconnTimer) {
      Loop::cancelTimer($this->_reconnTimer);
      $this->_reconnTimer = null;
    }

    $timeout = $this->_connTimeout = max(0,$timeout) ?: $this->_connTimeout ?: 5;
    $url = 'tcp://'.$this->_addr.':'.$this->_port;

    $ctx = [
      'unix' => false,
      'happy_eyeballs' => false,
      'timeout' => $timeout,
      'tls' => false
    ];
    $connector = new Connector($ctx);

    $this->_connPr = $connector->connect($url);
    $this->_connPr->then(
      function($conn) {
        $this->_onConnect($conn);
      },
      function($e) {
        $this->_connPr = null;
        $this->_emit('error-connect', [$e]);
        $this->_reconnect();
      }
    );
  }

  public function connected(): bool {
    return $this->_conn !== null;
  }

  private function _reconnect() {
    if(!$this->_reconnInterval) {
      $this->_emit('stop');
      return;
    }

    $this->_emit('reconnect', [$this->_reconnInterval]);

    if(!$this->_reconnInterval) {
      $this->_emit('stop');
      return;
    }

    $this->_reconnTimer = Loop::addTimer($this->_reconnInterval, function() {
      $this->connect();
    });
  }

  private function _onConnect($conn) {
    $this->_connPr = null;
    $this->_conn = $conn = new ObjectDecorator($conn);

    if(Log::loggerLoaded()) {
      Log::debug('MSGB_TCP: Client: New connection', 'msgb','tcp','client');
      Log::destruct($conn->obj(), 'MSGB_TCP: Client', 'msgb','tcp','client');
      $conn->hnd = new \SharkyDog\Log\Handle(function() {
        Log::debug('MSGB_TCP: Client: Close connection', 'msgb','tcp','client');
      });
    }

    $conn->buffer = new MessageBuffer(function($data) {
      $this->_conn->write($data);
    });
    $conn->buffer->on('data', function($data) {
      if(!($data=json_decode($data)) || !($data->t??null)) return;
      $this->_emit('message', [$data->t, $data->m??'', $data->n??'']);
    });

    $conn->on('data', function($data) {
      $this->_conn->buffer->feed($data);
    });
    $conn->on('close', function() {
      $this->_onClose();
    });

    if($this->_name) {
      $this->send('_name', $this->_name);
    }

    $this->_emit('open');
  }

  private function _onClose() {
    $this->_conn->buffer->removeAllListeners();
    $this->_conn->buffer = null;
    $this->_conn = null;

    $this->_emit('close', [!$this->_closing]);

    if($this->_closing) {
      $this->_emit('stop');
    } else {
      $this->_reconnect();
    }
  }

  public function send(string $topic, string $msg, ?string $from=null) {
    if(!$this->_conn) return;
    $this->_conn->buffer->send(json_encode(['t'=>$topic,'m'=>$msg,'n'=>$from?:'']));
  }

  public function end() {
    if(!$this->_conn || $this->_closing) return;
    $this->_closing = true;
    $this->_conn->end();
  }

  public function close() {
    if(!$this->_conn) return;
    $this->_closing = true;
    $this->_conn->close();
  }
}
