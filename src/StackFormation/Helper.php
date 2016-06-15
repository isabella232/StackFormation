<?php

namespace StackFormation;

class Helper
{

    public static function matchWildcard($wildcard_pattern, $haystack)
    {
        $regex = str_replace(
            ["\*", "\?"],
            ['.*', '.'],
            preg_quote($wildcard_pattern)
        );

        return preg_match('/^' . $regex . '$/is', $haystack);
    }

    public static function find($wildcardPatterns, array $choices)
    {
        if (!is_array($wildcardPatterns)) {
            $wildcardPatterns = [$wildcardPatterns];
        }
        $found = [];
        foreach ($choices as $choice) {
            foreach ($wildcardPatterns as $wildcardPattern) {
                if (self::matchWildcard($wildcardPattern, $choice)) {
                    $found[] = $choice;
                }
            }
        }
        $found = array_unique($found);
        return $found;
    }

    public static function isValidArn($arn)
    {
        return preg_match('/a-zA-Z][-a-zA-Z0-9]*|arn:[-a-zA-Z0-9:/._+]*/', $arn);
    }

    public static function extractMessage(\Aws\CloudFormation\Exception\CloudFormationException $exception)
    {
        $message = (string)$exception->getResponse()->getBody();
        $xml = simplexml_load_string($message);
        if ($xml !== false && $xml->Error->Message) {
            return $xml->Error->Message;
        }

        return $exception->getMessage();
    }

    public static function decorateStatus($status)
    {
        if (strpos($status, 'IN_PROGRESS') !== false) {
            return "<fg=yellow>$status</>";
        }
        if (strpos($status, 'COMPLETE') !== false) {
            return "<fg=green>$status</>";
        }
        if (strpos($status, 'FAILED') !== false) {
            return "<fg=red>$status</>";
        }

        return $status;
    }

    public static function decorateChangesetAction($changeSetAction)
    {
        if ($changeSetAction == 'Modify') {
            return "<fg=yellow>$changeSetAction</>";
        }
        if ($changeSetAction == 'Add') {
            return "<fg=green>$changeSetAction</>";
        }
        if ($changeSetAction == 'Remove') {
            return "<fg=red>$changeSetAction</>";
        }
        return $changeSetAction;
    }

    public static function decorateChangesetReplacement($changeSetReplacement)
    {
        if ($changeSetReplacement == 'Conditional') {
            return "<fg=yellow>$changeSetReplacement</>";
        }
        if ($changeSetReplacement == 'False') {
            return "<fg=green>$changeSetReplacement</>";
        }
        if ($changeSetReplacement == 'True') {
            return "<fg=red>$changeSetReplacement</>";
        }
        return $changeSetReplacement;
    }

    public static function findCloudWatchLogGroupByStream($stream, $logGroupNamePrefix=null)
    {
        $cloudWatchLogClient = \AwsInspector\SdkFactory::getClient('CloudWatchLogs'); /* @var $cloudWatchLogClient \Aws\CloudWatchLogs\CloudWatchLogsClient */
        $params = [];
        if ($logGroupNamePrefix) {
            $params['logGroupNamePrefix'] = $logGroupNamePrefix;
        }
        $resGroups = $cloudWatchLogClient->describeLogGroups($params);
        foreach ($resGroups->search('logGroups[].logGroupName') as $logGroupName) {
            $resStreams = $cloudWatchLogClient->describeLogStreams([
                'logGroupName' => $logGroupName,
                'orderBy' => 'LastEventTime'
            ]);
            foreach ($resStreams->search('logStreams[].logStreamName') as $logStreamName) {
                if ($stream == $logStreamName) {
                    return $logGroupName;
                }
            }
        }
        return null;
    }

    public static function validateTags(array $tags)
    {
        // @see http://docs.aws.amazon.com/AWSEC2/latest/UserGuide/Using_Tags.html#tag-restrictions
        if (count($tags) > 10) {
            throw new \Exception('No more than 10 tags are allowed');
        }
        foreach ($tags as $tag) {
            // key
            if (!isset($tag['Key'])) { throw new \Exception('Tag key is missing'); }
            $key = $tag['Key'];
            if (strlen($key) > 127) { throw new \Exception('Keys cannot be longer than 127 characters'); }
            if (!preg_match('/^[a-zA-Z0-9_\-+=:\/@\.]{1,127}$/', $key)) { throw new \Exception('Invalid characters in key'); }
            if (strpos($key, 'aws:') === 0) { throw new \Exception('The aws: prefix cannot be used for keys'); }

            // value
            if (!isset($tag['Value'])) { throw new \Exception('Tag value is missing'); }
            $value = $tag['Value'];
            if (strlen($value) > 255) { throw new \Exception('Values cannot be longer than 255 characters'); }
            if (!preg_match('/^[a-zA-Z0-9_\-+=:\/@\.]{1,255}$/', $value)) { throw new \Exception('Invalid characters in value'); }
        }
    }

}
