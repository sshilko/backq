/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2016-2019 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

/**
 * Lambda function example using Node.js 8.10
 *
 * This function is setup as trigger for DynamoDB Streams. The Streams record all
 * events from DynamoDB tables: Inserts, Updates, Removals.
 *
 * The function processes only items that were expired by Dynamo and sends the item
 * body to an SQS queue. It is assumed that the DynamoDB table and the SQS queue
 * have the same name (same requirement on DynamoSQS adapter).
 *
 * Moreover, it calculates the delay between expected item TTL trigger and actual
 * triggered time and sends it as a custom CloudWatch metric.
 *
 * The required permissions for the lambda role beyond the basic default execution ones,
 * are attached as stream_process_role_policy.json
 *
 * Notes for the environment variables (process.env.X):
 * - SQS_URL_PREFIX: should be set as https://sqs.{region}.amazonaws.com/{accountId}/
 * - SQS_QUEUE1_NAME, SQS_QUEUE2_NAME...: names of the supported SQS queues
 * - CLOUDWATCH_METRIC_NAMESPACE: required property to define a new namespace on CloudWatch
 */
var target_event_name   = 'REMOVE';
var principal_id_dynamo = 'dynamodb.amazonaws.com';

var AWS        = require('aws-sdk');
var sqs        = new AWS.SQS();
var cloudWatch = new AWS.CloudWatch();

exports.handler = async (event, context) => {

    for (const record of event.Records) {
        console.log('New event: ', record.eventName);
        if (record.eventName !== target_event_name) {
            // Nothing to process
            continue;
        }

        // Records for items that are deleted by TTL contain userIdentity metadata
        if (record.hasOwnProperty("userIdentity") && record.userIdentity.principalId == principal_id_dynamo) {
            console.log('TTL expired for ', JSON.stringify(record.dynamodb));

            const payload = AWS.DynamoDB.Converter.unmarshall(record.dynamodb.OldImage);
            console.log(payload);

            if (Object.keys(payload).length > 0) {
                let now = Math.floor(Date.now() / 1000);

                // Determine destination queue from stream table
                let queueUrl = process.env.SQS_URL_PREFIX;

                // DynamoDB streams expected as arn:aws:dynamodb:{region}:{acct_id}:table/{table_name}/...
                let sourceTable = record.eventSourceARN.split("/", 2)[1];

                switch (sourceTable) {
                    case process.env.SQS_QUEUE1_NAME:
                    case process.env.SQS_QUEUE2_NAME:
                        queueUrl += sourceTable;
                        break;

                    default:
                        console.log('Invalid DynamoDB table name ', sourceTable);
                        return {statusCode: 500};
                }

                try {
                    const response = await processReadyItem(JSON.stringify(payload), queueUrl);
                    console.log('Sent to SQS ', queueUrl, ' with request-id ', response.ResponseMetadata.RequestId);
                } catch (error) {
                    console.log(error);
                    return {statusCode: 500};
                }

                try {
                    // How long was the item expired after the TTL value?
                    let delay_time = now - payload.time_ready;
                    await sendEventDelay(sourceTable, delay_time);
                } catch (error) {
                    console.log('Cloudwatch error ', error);
                    return {statusCode: 500};
                }
            }
        }
    }
    return `Successfully processed ${event.Records.length} records.`;
};

const processReadyItem = async (payload, queue) => {
    let params = {
        MessageBody: payload,
        QueueUrl:    queue
    };

    return sqs.sendMessage(params).promise();
};

const sendEventDelay = async(sourceTable, delayTime) => {
    let params = {
        MetricData: [
            {
                MetricName: 'ApproximateDelayTime',
                Dimensions: [
                    {
                        Name:  'Per Source',
                        Value: sourceTable
                    }
                ],
                Unit:  'Seconds',
                Value: delayTime
            }
        ],
        Namespace: process.env.CLOUDWATCH_METRIC_NAMESPACE
    };
    console.log('Seconds delay:', delayTime);
    return cloudWatch.putMetricData(params).promise();
};