/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2016-2020 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 */

/**
 * Lambda function example using Node.js 18.x runtime and AWS SDK for JavaScript v3
 *
 * This function is configured as a trigger for DynamoDB Streams. The Streams
 * record all events from DynamoDB tables: Inserts, Updates, Removals.
 *
 * It processes only items that were expired by DynamoDB TTL and sends the item
 * body to an SQS queue. The DynamoDB table and the SQS queue share the same
 * name (same requirement as the DynamoSQS adapter).
 *
 * It also calculates the delay between the expected item TTL trigger and the
 * actual trigger time and publishes it as a custom CloudWatch metric.
 *
 * Configure the environment variables via `environment.txt`:
 * - EVENT_REMOVE: event name that triggers processing (default: REMOVE)
 * - DYNAMODB_PRINCIPAL_ID: principalId metadata marker of TTL deletions
 * - SQS_URL_PREFIX: https://sqs.{region}.amazonaws.com/{accountId}/
 * - SQS_FAST, SQS_SLOW: names of the supported SQS queues (and DynamoDB tables)
 * - CLOUDWATCH_METRIC_NAMESPACE: CloudWatch namespace for the delay metric
 */
const {
    SQSClient,
    SendMessageCommand
} = require('@aws-sdk/client-sqs');
const {
    CloudWatchClient,
    PutMetricDataCommand
} = require('@aws-sdk/client-cloudwatch');
const { unmarshall } = require('@aws-sdk/util-dynamodb');

const TARGET_EVENT_NAME = process.env.EVENT_REMOVE || 'REMOVE';
const PRINCIPAL_ID_DYNAMO = process.env.DYNAMODB_PRINCIPAL_ID || 'dynamodb.amazonaws.com';
const METRIC_NAMESPACE = process.env.CLOUDWATCH_METRIC_NAMESPACE || 'BackqScheduler';

const sqs = new SQSClient({});
const cloudWatch = new CloudWatchClient({});

exports.handler = async (event) => {
    console.info(event);

    for (const record of event.Records) {
        console.info('New event: ', record.eventName);

        if (record.eventName !== TARGET_EVENT_NAME) {
            // Nothing to process
            continue;
        }

        // Records for items deleted by TTL contain the userIdentity metadata
        if (!record.userIdentity || record.userIdentity.principalId !== PRINCIPAL_ID_DYNAMO) {
            continue;
        }

        console.info('TTL expired for ', JSON.stringify(record.dynamodb));

        const payload = unmarshall(record.dynamodb.OldImage);
        console.log(payload);

        if (Object.keys(payload).length > 0) {
            const now = Math.floor(Date.now() / 1000);
            const sourceTable = record.eventSourceARN.split('/', 2)[1];

            let queueUrl = process.env.SQS_URL_PREFIX;
            switch (sourceTable) {
                case process.env.SQS_FAST:
                case process.env.SQS_SLOW:
                    queueUrl += sourceTable;
                    break;

                default:
                    console.error('Invalid DynamoDB table name ', sourceTable);
                    return { statusCode: 500 };
            }

            try {
                await sqs.send(new SendMessageCommand({ MessageBody: JSON.stringify(payload), QueueUrl: queueUrl }));
                console.log('Sent to SQS ', queueUrl);
            } catch (error) {
                console.error(error);
                return { statusCode: 500 };
            }

            try {
                // How long was the item expired after the TTL value?
                const delayTime = now - payload.time_ready;
                await cloudWatch.send(new PutMetricDataCommand({
                    MetricData: [
                        {
                            MetricName: 'ApproximateDelayTime',
                            Dimensions: [{ Name: 'Per Source', Value: sourceTable }],
                            Unit: 'Seconds',
                            Value: delayTime
                        }
                    ],
                    Namespace: METRIC_NAMESPACE
                }));
                console.info('Seconds delay:', delayTime);
            } catch (error) {
                console.error('CloudWatch error ', error);
                return { statusCode: 500 };
            }
        }
    }

    return `Successfully processed ${event.Records.length} records.`;
};