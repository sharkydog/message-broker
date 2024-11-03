<?php
namespace SharkyDog\MessageBroker\Local;
use SharkyDog\MessageBroker as MSGB;

class MessageParser {
  public function __construct(
    callable $parser, MSGB\Server $broker,
    string $topicFrom, string $topicTo,
    string $name=''
  ) {
    $client = new Client($name ?: '_msg_parser_'.spl_object_id($this).'_', $broker);

    $client->on('message', function($topic,$msg,$from) use($parser,$client,$topicFrom,$topicTo,$name) {
      if($topic == 'broker/subscribers') {
        list($topic,$count) = explode(' ',$msg);

        if($count = (int)$count) {
          $client->send('broker/subscribe', $topicFrom);
        } else {
          $client->send('broker/unsubscribe', $topicFrom);
        }

        return;
      }

      if($msg = $parser($msg,$topic,$from)) {
        $client->send($topicTo, $msg, $name?:$from);
      }
    });

    $client->send('broker/subscribers', $topicTo);
  }
}
