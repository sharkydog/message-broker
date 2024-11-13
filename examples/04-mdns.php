<?php

// include this file or add your autoloader


use SharkyDog\MessageBroker as MSGB;
use SharkyDog\mDNS;
use React\EventLoop\Loop;

function pn($d) {
  print "*** Ex.04: ".print_r($d,true)."\n";
}

// helper to print messages from broker
function pmsg($app,$topic,$msg,$from) {
  pn($app.': from '.$from.' on '.$topic.': '.$msg);
}

//MSGB\Log::level(99);

try {
  $svc1 = new MSGB\Service;
  $svc1->WSListen('0.0.0.0', 23480);
  $svc1->WSRoute('/ws/broker1');
  $svc1->TCPListen('0.0.0.0', 23490);

  // testing
  // wait a little as the bridge will connect through a websocket
  // and exchange subscriptions between the two brokers
  // the event driven way would be to have $broker1_client wait
  // until being notified for subscribers on 'test/hello' topic
  Loop::addTimer(0.3, function() use($svc1) {
    $broker1_client = new MSGB\Local\Client('client_broker1', $svc1->MSGB());
    $broker1_client->send('test/hello', 'hello there');
  });

  $mdnsd = new mDNS\SimpleResponder;
  // IPv4 address of this host
  // returns loopback in my setup, see bellow
  //$mdnsd->addRecordIPv4('server1-msgb.local', getHostByName(getHostName()));
  // service server1._msgbws._tcp.local on ws://server1-msgb.local:23480/ws/broker1, default TTL (120)
  $mdnsd->addService('_msgbws._tcp', 'server1', -1, 'server1-msgb.local', 23480, 'path=/ws/broker1');
  // service server1._msgbtcp._tcp.local on tcp://server1-msgb.local:23490, default TTL (120)
  $mdnsd->addService('_msgbtcp._tcp', 'server1', -1, 'server1-msgb.local', 23490);
  // start responder
  $mdnsd->start();

  // yes, this actually works
  // and doesn't give me 127.x.x.x like getHostByName(getHostName()) above
  mDNS\WhatIsMyIP::startResponderIPv4('192.168.0.0/16');
  mDNS\WhatIsMyIP::resolveIPv4('192.168.0.0/16')->then(function($addr) use($mdnsd) {
    pn('Found LAN IP address '.$addr.' through mDNS');
    $mdnsd->addRecordIPv4('server1-msgb.local', $addr);
  });


  // On some other host, a second broker
  //
  // bridge it to the first broker
  // after we find its address, port and path through mDNS
  $svc2 = new MSGB\Service;

  // testing
  $broker2_client = new MSGB\Local\Client('client_broker2', $svc2->MSGB());
  $broker2_client->send('broker/subscribe', 'test/*');
  $broker2_client->on('message', function($topic,$msg,$from) {
    pmsg('client_broker2',$topic,$msg,$from);
    Loop::stop();
  });

  // resolver
  $mdns_resolver = new mDNS\React\Resolver;

  // discoverer
  $mdns_discover = new mDNS\SimpleDiscoverer;

  // stop on first found
  $mdns_discover->filter(fn()=>true);
  // find instances of type '_msgbws._tcp.local', IPv4: yes, IPv6: no, TXTs: yes
  $mdns_discover->service('_msgbws._tcp.local',true,false,true)->then(
    function(array $services) use($svc2,$mdns_resolver) {
      $service = $services[0];

      // setup with IP address
      //$url = 'ws://'.$service->target[0]->address.':'.$service->port.$service->data['path'];
      // setup with domain name resolved with SharkyDog\mDNS\React\Resolver
      $url = 'ws://'.$service->target[0]->name.':'.$service->port.$service->data['path'];
      pn('Found service '.$service->name.' at '.$url);

      // bridge to first broker
      $bridge_client = $svc2->Bridge('br_svc1', $url);
      $bridge_client->resolver($mdns_resolver);
      $bridge_client->connect();
    },
    function(\Exception $e) {
      MSGB\Log::error($e->getMessage());
      Loop::stop();
    }
  );

  // Loop needs to run here to catch errors
  Loop::run();
} catch(\Exception $e) {
  MSGB\Log::error($e->getMessage());
  Loop::stop();
}
