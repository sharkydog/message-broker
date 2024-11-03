<?php

// include this file or add your autoloader


use SharkyDog\MessageBroker as MSGB;
use React\EventLoop\Loop;

function pn($d) {
  print "*** Ex.03: ".print_r($d,true)."\n";
}

// helper to print messages from broker
function pmsg($app,$topic,$msg,$from) {
  pn($app.': from '.$from.' on '.$topic.': '.$msg);
}

//MSGB\Log::level(99);

// The Service class provides an easy way to setup a broker
// and bridge it to other broker.
// When the loop runs, the service class will throw exceptions
// for anything it doesn't like.

try {
  $svc1 = new MSGB\Service;

  // websocket interface
  // same parameters as SharkyDog\HTTP\Server->listen()
  // TLS is the third parameter, not used in this example
  $svc1->WSListen('0.0.0.0', 23480);

  // http route for the websocket interface
  $svc1->WSRoute('/ws/broker1');

  // tcp server
  $svc1->TCPListen('0.0.0.0', 23490);

  //
  // Everything bellow is needed just for the example.
  // In the real world you probably do not need to run
  // two bridged brokers on the same machine
  //
  // Consider the following as it is being run on another host.
  //

  // Add a second broker
  $svc2 = new MSGB\Service;

  // and bridge it to the first broker
  $svc2->Bridge('br_svc1', 'tcp://127.0.0.1:23490');
  // or using a websocket
  //$svc2->Bridge('br_svc1', 'ws://127.0.0.1:23480/ws/broker1');

  // Bridge() will return the client object
  // if it's needed, like handling reconnects.
  // Can be called later
  //$br_svc1 = $svc2->Bridge('br_svc1');


  // Other useful methods of the service class.
  // See the service class for parameters and return types.
  // Can be used as setters only once, order is important.
  //
  // WSListen() and WSRoute() will call
  // HTTP() and WS() and create http server
  // and websocket handler if not set already
  //
  // TCPListen() will call TCP() to get or create a tcp server
  // Bridge() will call MSGB()
  //
  // Get or set the message broker server
  //$svc1->MSGB();
  //
  // Get or set the http server
  //$svc1->HTTP();
  //
  // Get or set the websocket handler
  // calls HTTP() and MSGB()
  //$svc1->WS();
  //
  // Get or set the tcp server
  // calls MSGB()
  //$svc1->TCP();


  //
  // Testing
  //

  $broker1_client = new MSGB\WebSocket\Client('ws://127.0.0.1:23480/ws/broker1', 'client_broker1');
  $broker1_client->connect();

  $broker2_client = new MSGB\Local\Client('client_broker2', $svc2->MSGB());
  $broker2_client->send('broker/subscribe', 'test/*');
  $broker2_client->on('message', function($topic,$msg,$from) {
    pmsg('client_broker2',$topic,$msg,$from);
  });

  Loop::addTimer(0.1, function() use($broker1_client) {
    $broker1_client->send('test/hello', 'hello there');
  });

  Loop::addTimer(0.3, function() {
    React\EventLoop\Loop::stop();
  });


  // Loop needs to run here to catch errors
  Loop::run();
} catch(\Exception $e) {
  MSGB\Log::error($e->getMessage());
  Loop::stop();
}
