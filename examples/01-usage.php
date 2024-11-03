<?php

// include this file or add your autoloader


use SharkyDog\HTTP;
use SharkyDog\MessageBroker as MSGB;

function pn($d) {
  print "*** Ex.01: ".print_r($d,true)."\n";
}

// helper to print messages from broker
function pmsg($app,$topic,$msg,$from) {
  pn($app.': from '.$from.' on '.$topic.': '.$msg);
}

//MSGB\Log::level(99);


//
// The message broker is intended to connect several apps
// and distribute messages between them.
// The apps can be connected locally or remotely,
// even through the big wild internet,
// though this will not be secure.
//
// The messaging system is based around topics and subscriptions,
// as with other message brokers.
//
// Messages are send only to peers that have subscribed to a given topic
// or to a wildcard topic (more on that later) matching it.
// There are no message queues or retention. No database.
// If a message is sent, it will either be received by connected peers
// or it will be lost forever, never to be seen again.
//
// Commands to the broker are sent as normal messages on broker/ topics.
//
//  broker/subscribe
//    Subscribe to topics.
//    Message text is a list of topics, separated by space or comma.
//    Can be wildcard topics, but not broker/ topics.
//
//  broker/subscribe/clear
//    Clear all subscriptions.
//    Empty message text.
//
//  broker/unsubscribe
//    Unsubscribe from a list of topics, separated by space or comma.
//
//  broker/subscribers
//    Get notified about subscriptions.
//    Message text is a list of topics, separated by space or comma.
//    Can not be wildcard topics.
//
//    When the number of subscribers for a topic changes,
//    the peer will receive a message on topic broker/subscribers
//    with text like 'some/topic 2', the topic to get notified for
//    and total subscribers, which can be zero.
//    Subscribers to wildcard topics that match the notification topic
//    are also counted.
//
//  broker/subscribers/all
//    Get notified about all subscriptions, including wildcard subscriptions.
//    Empty message text.

//    Peer will receive a broker/subscribers/all message
//    when subscribers change on any topic.
//    Message will be like 'some/topic 2',
//    but wildcard topics will not be matched to other subscriptions.
//    Instead, peer will be notified with the wildcard topic,
//    like 'some/* 2' if there are subscribers for 'some/*'.
//
//  broker/subscribers/clear
//    Clear all notifications.
//
// Wildcard topics
// A peer can subscribe for a wildcard topic and receive messages
// posted on all topics that match the wildcard.
//
//  'some+' match 'someA','something', but not 'some' and 'some/topic'
//  'some/+/topic' match 'some/a/topic', not 'some/a/b/topic'
//  'some*' match anything starting with 'some', but not just 'some'
//  'some/*/topic match 'some/a/topic' and 'some/a/b/topic'
//

// In short, the following class shows how and app can be connected to the broker.
// This kind of usage allows an app to add multiple peers.
// It is more suitable for providing an interface to the broker,
// not for end apps that subscribe and publish messages.
//
class App1 {
  private $_peer;

  public function __construct(MSGB\Server $broker) {
    // This is the receiver callback.
    // Messages from the broker land here.
    // $from is the peer the message is comming from
    // $name is a sender name that may have been set by the $from peer
    // Clients decribed later use $name if not null or $from->name as seen here.
    $msgFn = function(string $topic, string $msg, MSGB\Peer $from, ?string $name) {
      $this->_onMessage($topic, $msg, $name?:$from->name);
    };
    $this->_peer = new MSGB\Peer('app1', $msgFn);
    $broker->addPeer($this->_peer);
    $this->_peer->sendToBroker('broker/subscribe', 'app/some-api');
  }

  public function someApi() {
    $this->_peer->sendToBroker('test/hello', 'Hello there');
  }

  private function _onMessage($topic, $msg, $from) {
    pmsg('App1',$topic,$msg,$from);
  }
}

// There preferred way to connect an app.
// This class accepts an object that implements SharkyDog\MessageBroker\ClientInterface
// instead of SharkyDog\MessageBroker\Server instance.
// This client can be local or a remote broker.
// As seen here, a ClientInterface object will emit 'open','close' and 'message' events.
//
class App2 {
  private $_client;

  public function __construct(MSGB\ClientInterface $client) {
    $this->_client = $client;

    $client->on('open', function() {
      pn(static::class.': connected to broker');
      $this->_client->send('broker/subscribe', 'app/some-api');
    });
    $client->on('close', function() {
      pn(static::class.': disconnected from broker');
    });
    $client->on('message', function($topic,$msg,$from) {
      $this->_onMessage($topic, $msg, $from);
    });
  }

  public function someApi() {
    // Here we use the class name as peer name.
    // Later, we will extend this class to use it with remote client
    $this->_client->send('test/hello', 'Hello there', static::class);
  }

  private function _onMessage($topic, $msg, $from) {
    pmsg(static::class,$topic,$msg,$from);
  }
}

// Create our broker
$broker = new MSGB\Server;

// Usage with App1
$app1 = new App1($broker);

// The preferred way
// 'app2' is the peer name that will be used if not set with client->send()
$app2 = new App2(new MSGB\Local\Client('app2', $broker));

// another local client to play around with
$local1 = new MSGB\Local\Client('local1', $broker);
$local1->on('message', function($topic,$msg,$from) {
  pmsg('local1',$topic,$msg,$from);
});

// the test client will receive messages on any test/ subtopic,
// but not on something like test/sub/subsub
$local1->send('broker/subscribe', 'test/+');

// our apps subscribed to app/some-api
// send them a message
$local1->send('app/some-api', 'hello apps');

// apps will publish on test/hello
$app1->someApi();
$app2->someApi();


// Remote interfaces
// The clients can be used in App2


// Accept connections through TCP.
$tcpd = new MSGB\TCP\Server;
$tcpd->listen('0.0.0.0', 23490);
$tcpd->on('peer', fn($peer) => $broker->addPeer($peer));

// TCP client, 'tcp1' is a name as described for the local client (App2)
$tcpc = new MSGB\TCP\Client('127.0.0.1', 23490, 'tcp1');
//
// attempt to reconnect 2 seconds after connection loss (default)
// disable with reconnect(0)
// reconnect will not trigger if connection is closed locally
// using TCP\Client->end() and TCP\Client->close();
//$tcpc->reconnect(2);

$tcpc->connect();
$tcpc->on('open', function() use($tcpc) {
  $tcpc->send('app/some-api', 'hello apps');
});

// On connect failure, an 'error-connect' event will be emitted
// with one parameter, an Exception object.
// Reconnect will be triggered if not disabled.

// The 'close' event has one boolean parameter
// true if reconnect will be attempted
$tcpc->on('close', function($reconnect) {
  pn('TCP client close, reconnect: '.($reconnect ? 'yes' : 'no'));
});
// And a 'reconnect' event will be fired when a reconnect attempt is made
// Interval is the time set with reconnect()
$tcpc->on('reconnect', function($interval) {
  pn('TCP client reconnect after '.$interval.'s');
});
// Final event, after close or error-connect and disabled reconnect
$tcpc->on('stop', function() {
  pn('TCP client stop!');
});


// These are better explained in the sharkydog/http package.
// Websocket server
$wsh = new MSGB\WebSocket\Handler;
$wsh->on('peer', fn($peer) => $broker->addPeer($peer));
// HTTP server
$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/ws/broker', $wsh);

// Websocket client
// Emits the same events and have the same reconnect logic as the TCP client.
$wsc = new MSGB\WebSocket\Client('ws://127.0.0.1:23480/ws/broker', 'ws1');
//$wsc->reconnect(2);

$wsc->connect();
$wsc->on('open', function() use($wsc) {
  $wsc->send('app/some-api', 'hello apps');
});


//
// Important note regarding 'open' and 'close' events.
//
// SharkyDog\MessageBroker\Local\Client
//   Will not attach listeners via on() and once()
//   for 'open' and 'close', instead
//   'open' listener will be called immediately
//   'close' will not.
//   A local client is always open and never closes.
//
// SharkyDog\MessageBroker\TCP\Client
// and
// SharkyDog\MessageBroker\WebSocket\Client
//   Will attach listeners,
//   so apps can react on future connects and disconnects.
//   But also will call the listener being attached for open
//   immediately if client is already connected.
//



// Now use with remote client
class App3 extends App2 {}
$wsc_app3 = new MSGB\WebSocket\Client('ws://127.0.0.1:23480/ws/broker');
$wsc_app3->connect();
$app3 = new App3($wsc_app3);

$wsc_app3->on('open', function() use($app3,$local1) {
  // app3 is just connected and subscribed to app/some-api
  // and can send a message
  $app3->someApi();
  // but can't receive one yet
  // loop needs a little time
  // since this is all happening through tcp sockets
  React\EventLoop\Loop::addTimer(0.1, function() use($local1) {
    $local1->send('app/some-api', 'hello apps');
  });
});

// Everything should complete in a lot less than a second
React\EventLoop\Loop::addTimer(0.3, function() {
  React\EventLoop\Loop::stop();
});
