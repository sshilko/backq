## Stream process lambda function ##

[`backq-scheduled-stream`](backq-scheduled-stream.js) is a Lambda function setup as trigger for DynamoDB Streams. The Streams record all events from DynamoDB tables: Inserts, Updates, Removals.

The function processes only items that were expired by Dynamo and sends the item body to an SQS queue. It is assumed that the DynamoDB table and the SQS queue have the same name (same requirement on DynamoSQS adapter).

Moreover, it calculates the delay between expected item TTL trigger and actual triggered time and sends it as a custom CloudWatch metric.

The latest function version uses the Node.js 12.x runtime. It should be executed relatively fast and has 3 seconds timeout.


### DynamoDB Stream/TTL setup ###

**Stream**

By default, Dynamo Streams are disabled. It can be enabled via the *Overview* tab for a DynamoDB table. It is not possible setting up a stream for a specific event, so everything will be saved on the stream.

To setup a stream, there are multiple views for the affected items: KEYS_ONLY, NEW_IMAGE, OLD_IMAGE, NEW_AND_OLD_IMAGES. Since the function will only need `removed` items, it is set as the **OLD_IMAGE** only.

Payloads received by the function have the full item contents that can be converted to a readable JSON format via `DynamoDB.Converter.unmarshall`.

Streams are set as lambda function triggers and the batch size can be configured as wished. We keep batches of 10 items.

**Time to live attribute (TTL)**

By default, DynamoDB tables won't have a time to live attribute. It can be easily configured from the *Overview* tab and only requires the name of the attribute that will represent the TTL, which has to be **Number** datatype. Dynamo has a known delay for the actual removal time when TTLs were reached.


### Environment differences ###

The lambda function has exactly the same source code per environment. Via [environment](environment.txt) variables, different resource names for test/production/... can be accessed.


### Lambda execution role policy ##

The lambda role needs permissions beyond the basic ones to:
- Read DynamoDB streams from every processing table.
- Send messages to the linked SQS queues.
- Send custom metrics to CloudWatch.

Check [minimum working policy](backq-scheduled-stream-policy.json).