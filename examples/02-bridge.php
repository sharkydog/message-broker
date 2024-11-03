<?php

// include this file or add your autoloader


use SharkyDog\HTTP;
use SharkyDog\MessageBroker as MSGB;

function pn($d) {
  print "*** Ex.02: ".print_r($d,true)."\n";
}

// helper to print messages from broker
function pmsg($app,$topic,$msg,$from) {
  pn($app.': from '.$from.' on '.$topic.': '.$msg);
}

//MSGB\Log::level(99);

//
// A bridge is like a network switch that connects multiple brokers.
// Subscription on one broker is forwarded to all other brokers.
// Messages comming from a given broker are passed only to brokers
// that have subscribers for the topic.
// It's like another broker between brokers, but the bridge
// connects brokers, rather than peers.
//
// Bridge connections are made with the same clients
// used to connect apps (peers), so it is possible,
// but not advised (security) to connect brokers across the big internet.
//
// Also possible, but not advised is to create a large network of brokers.
// Every broker will receive every subscription, so every peer
// will be able to talk to every other peer.
//
// Bridges are meant to link a local broker running on one host,
// connecting few local apps, to a central broker (on your local network)
// where you will be creating the interfces to those apps (a web site, rest service, etc).
//
// So, multiple (local) hosts would be able to provide data to your main (local) super server.
//

// First make some brokers
$broker1 = new MSGB\Server;
$broker2 = new MSGB\Server;

// Then a websocket interface for one of them ($broker1)
$wsh = new MSGB\WebSocket\Handler;
$wsh->on('peer', fn($peer) => $broker1->addPeer($peer));
$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 23480);
$httpd->route('/ws/broker1', $wsh);

// And a websocket client
$wsc = new MSGB\WebSocket\Client('ws://127.0.0.1:23480/ws/broker1', 'bridge1_ws_msgb1');
$wsc->connect();

// The bridge
$br1 = new MSGB\Bridge;
// connect it to broker1
// first parameter is key,
// not important, but needs to be unique for every broker
// second parameter is a client (SharkyDog\MessageBroker\ClientInterface)
$br1->broker('broker1', $wsc);
// connect to broker2
$br1->broker('broker2', new MSGB\Local\Client('bridge1_loc_msgb2', $broker2));

// Let's see what happens

$broker1_client = new MSGB\Local\Client('client_broker1', $broker1);
$broker2_client = new MSGB\Local\Client('client_broker2', $broker2);

$broker2_client->send('broker/subscribe', 'test/*');
$broker2_client->on('message', function($topic,$msg,$from) {
  pmsg('client_broker2',$topic,$msg,$from);
});

// Just wait for a moment, bridge should be connected and subscription passed
React\EventLoop\Loop::addTimer(0.1, function() use($broker1_client) {
  $broker1_client->send('test/hello', 'hello there');
});

// stop
React\EventLoop\Loop::addTimer(0.3, function() {
  React\EventLoop\Loop::stop();
});
