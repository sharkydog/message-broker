# message-broker
Message Broker to interconnect PHP apps

The message broker is intended to connect several apps
and distribute messages between them.

The apps can be connected locally or remotely,
even through the big wild internet,
though this will not be secure.

The messaging system is based around topics and subscriptions,
as with other message brokers.

Messages are send only to peers that have subscribed to a given topic
or to a wildcard topic matching it.
There are no message queues or retention. No database.
If a message is sent, it will either be received by connected peers
or it will be lost forever, never to be seen again.

See [main/examples](https://github.com/sharkydog/message-broker/tree/main/examples) for how to use.
