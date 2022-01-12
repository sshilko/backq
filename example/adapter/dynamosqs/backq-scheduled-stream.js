/**
 * Backq: Background tasks with workers & publishers via queues
 *
 * Copyright (c) 2016-2020 Carolina Alarcon
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

/**
 * Lambda function example using Node.js 12.x
 *
 * Notes for the environment variables (process.env.X):
 * - SQS_URL_PREFIX: should be set as https://sqs.{region}.amazonaws.com/{accountId}/
 * - SQS_FAST, SQS_SLOW...: names of the supported SQS queues
 * - CLOUDWATCH_METRIC_NAMESPACE: required property to define a new namespace on CloudWatch
 */
const TARGET_EVENT_NAME   = process.env.EVENT_REMOVE;
const PRINCIPAL_ID_DYNAMO = process.env.DYNAMODB_PRINCIPAL_ID;

const AWS        = require('aws-sdk');
const sqs        = new AWS.SQS();
const cloudWatch = new AWS.CloudWatch();

exports.handler = async (event, context) => {
    console.info(event);
    for (const record of event.Records) {
        console.info('New event: ', record.eventName);
        if (record.eventName !== TARGET_EVENT_NAME) {
            // Nothing to process
            continue;
        }

        // Records for items that are deleted by TTL contain userIdentity metadata
        if (record.hasOwnProperty("userIdentity") && record.userIdentity.principalId == PRINCIPAL_ID_DYNAMO) {
            console.info('TTL expired for ', JSON.stringify(record.dynamodb));

            const payload = AWS.DynamoDB.Converter.unmarshall(record.dynamodb.OldImage);
            console.log(payload);
            
            if (Object.keys(payload).length > 0) {
                let now = Math.floor(Date.now() / 1000);
                
                // Determine destination queue from stream table
                // DynamoDB streams expected as arn:aws:dynamodb:{region}:{acct_id}:table/{table_name}/...
                let queueUrl     = process.env.SQS_URL_PREFIX;
                let sourceTable  = record.eventSourceARN.split("/", 2)[1];

                switch (sourceTable) {
                    case process.env.SQS_FAST:
                    case process.env.SQS_SLOW:
                        queueUrl += sourceTable;
                        break;
                    
                    default:
                        console.warn('Invalid DynamoDB table name ', sourceTable);
                        return {statusCode: 500};
                }

                try {
                    const response = await processReadyItem(JSON.stringify(payload), queueUrl);
                    console.log('Sent to SQS ', queueUrl, ' with request-id ', response.ResponseMetadata.RequestId);
                } catch (error) {
                    console.warn(error);
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
    console.info('Seconds delay:', delayTime);
    return cloudWatch.putMetricData(params).promise();
};
