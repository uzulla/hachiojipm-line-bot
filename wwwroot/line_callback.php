<?php
require __DIR__ . "/../settings.php";
require __DIR__ . "/../vendor/autoload.php";

define('LAST_BEACON_LOG_DIR',__DIR__."/../last_beacon_log/");

$bot = new \LINE\LINEBot(
    new \LINE\LINEBot\HTTPClient\CurlHTTPClient(LINE_MESSAGING_API_CHANNEL_TOKEN),
    ['channelSecret' => LINE_MESSAGING_API_CHANNEL_SECRET]
);

if (!isset($_SERVER["HTTP_" . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE])) {
    error_log("Signature header missing");
    responseBadRequest('Signature header missing');
}
$signature = $_SERVER["HTTP_" . \LINE\LINEBot\Constant\HTTPHeader::LINE_SIGNATURE];

$body = file_get_contents("php://input");

$events = [];
try {
    $events = $bot->parseEventRequest($body, $signature);
} catch (\Exception $e) {
    error_log("Fail event parse :" . get_class($e));
    responseBadRequest("Fail event parse");
}

foreach ($events as $event) {
    if ($event instanceof \LINE\LINEBot\Event\MessageEvent) {
        if ($event instanceof \LINE\LINEBot\Event\MessageEvent\TextMessage) {
            $profile_data = $bot->getProfile($event->getUserId())->getJSONDecodedBody();
            error_log("8P BOT MESSAGE: {$event->getUserId()}: {$profile_data['displayName']} {$event->getText()}");
            notify("8P BOT MESSAGE: {$event->getUserId()}: {$profile_data['displayName']}: {$event->getText()}");

            generate_response_message($bot, $event);

//        } elseif ($event instanceof \LINE\LINEBot\Event\MessageEvent\StickerMessage) {
//        } elseif ($event instanceof \LINE\LINEBot\Event\MessageEvent\LocationMessage) {
//        } elseif ($event instanceof \LINE\LINEBot\Event\MessageEvent\ImageMessage) {
//        } elseif ($event instanceof \LINE\LINEBot\Event\MessageEvent\AudioMessage) {
//        } elseif ($event instanceof \LINE\LINEBot\Event\MessageEvent\VideoMessage) {
//        } else {
//            // Just in case...
//            error_log('Unknown message type has come');
//            continue;
        }
    } elseif ($event instanceof \LINE\LINEBot\Event\FollowEvent) {
        $profile_data = $bot->getProfile($event->getUserId())->getJSONDecodedBody();
        error_log("8P BOT FOLLOWED: {$event->getUserId()}: {$profile_data['displayName']}");
        notify("8P BOT FOLLOWED: {$event->getUserId()}: {$profile_data['displayName']}");

        $reply_token = $event->getReplyToken();
        $bot->replyText($reply_token, "Thanks follow!!!");

    } elseif ($event instanceof \LINE\LINEBot\Event\BeaconDetectionEvent) {

        // これはひどい
        $logfile = LAST_BEACON_LOG_DIR.$event->getUserId();
        if(!file_exists($logfile) || filemtime($logfile) < time()-86400){
            // expireしてる12q1
            file_put_contents($logfile, ''); // update mtime

            $profile_data = $bot->getProfile($event->getUserId())->getJSONDecodedBody();

            error_log("8P BOT BEACON: {$event->getUserId()}: {$profile_data['displayName']}");
            notify("8P BOT BEACON: {$event->getUserId()}: {$profile_data['displayName']}");

            post_to_json_api(SLACK_WEBHOOK_8P_ATND_URL, [
                'text' => "はちぴー参加成功！！",
                'username' => $profile_data['displayName'],
                "icon_url" => $profile_data['pictureUrl']
            ]);

            $reply_token = $event->getReplyToken();
            $bot->replyText($reply_token, "はちぴー参加成功！！");
        }

//    } elseif ($event instanceof \LINE\LINEBot\Event\UnfollowEvent) {
//        $profile = $bot->getProfile($event->getUserId());
//        error_log('un follow: '.$event->getUserId()." ".print_r($profile,1));

//    } elseif ($event instanceof \LINE\LINEBot\Event\JoinEvent) {
//    } elseif ($event instanceof \LINE\LINEBot\Event\LeaveEvent) {
//    } elseif ($event instanceof \LINE\LINEBot\Event\PostbackEvent) {
//    } else {
//        // Just in case...
//        error_log('Unknown message type has come');
//        continue;
    }
}

echo "OK";
exit; // FIN

function responseBadRequest($reason)
{
    http_response_code(400);
    echo 'Bad request, ' . $reason;
    exit;
}

function notify($str)
{
    $line_notify = new \Uzulla\Net\LineNotifySimpleLib('', '');
    $line_notify->sendMessage(LINE_NOTIFY_ACCESS_TOKEN, mb_substr($str, 0, 1000));
}

function generate_response_message(
    \LINE\LINEBot $bot,
    \LINE\LINEBot\Event\MessageEvent\TextMessage $event
)
{
    $reply_token = $event->getReplyToken();
    $profile_data = $bot->getProfile($event->getUserId())->getJSONDecodedBody();
    $text = $event->getText();

    if (preg_match('/^ping$/i', $text)) {
        $bot->replyText($reply_token, 'PONG');

    } elseif (preg_match('/^(次のはちぴーは？|次回)$/', $text)) {
        $next_event = get_next_8p_atnd_event_data();

        if ($next_event === false) {
            $return = '次回は未定です、主催をせっつくなりしてください';
        } else {
            $return = "次回は" . date("Y-m-d H:i", strtotime($next_event['started_at'])) . "からです。\n";
            $return .= "タイトルは「{$next_event['title']}」\n";
            $return .= "場所は{$next_event['place']}です。\n";
            $return .= "くわしくはATNDにて!!\n {$next_event['event_url']}";
        }

        $bot->replyText($reply_token, $return);

    } elseif (preg_match('/^(次回ATNDはよ|次回まだ？)$/', $text)) {
        post_to_json_api(SLACK_WEBHOOK_8P_ATND_URL, [
            'text' => "次回はちぴーATNDはよ",
            'username' => $profile_data['displayName'],
            "icon_url" => $profile_data['pictureUrl']
        ]);

        $bot->replyText($reply_token, 'Slackになげときましたね！');

    } elseif (preg_match('/^(地図だして|地図)$/', $text)) {

        $next_event = get_next_8p_atnd_event_data();

        if ($next_event === false) {
            $bot->replyText($reply_token, '次回は未定です、主催をせっつくなりしてください');
            return;
        }

        $bot->replyMessage(
            $reply_token,
            new \LINE\LINEBot\MessageBuilder\LocationMessageBuilder(
                $next_event['place'],
                $next_event['address'],
                $next_event['lat'],
                $next_event['lon'])
        );

    } elseif (preg_match('/^(私は押してはダメとかいてあるボタンを押してしまうほど自制心がありません)$/', $text)) {

        $bot->replyMessage(
            $reply_token,
            new \LINE\LINEBot\MessageBuilder\StickerMessageBuilder(
                1,
                116
                )
        );

        if(preg_match("/papix/", $profile_data['displayName'])) {
            post_to_json_api(SLACK_WEBHOOK_8P_GENERAL_URL, [
                'text' => "このボタンでガチャが引けるとおもってました、ごめんなさい。",
                'username' => $profile_data['displayName'],
                "icon_url" => $profile_data['pictureUrl']
            ]);
        }

    } else {
        $bot->replyText($reply_token, "「次回」、「地図」などを入力してみてください。\nあるいはメニューをひらいてボタンで操作！！");
    }
}

function post_to_json_api($url, array $data)
{
    $json = json_encode($data);

    $header = array(
        "Content-Type: application/json",
        "Content-Length: " . strlen($json)
    );

    $context = stream_context_create(array(
        "http" => array(
            "method" => "POST",
            "header" => implode("\r\n", $header),
            "content" => $json
        )
    ));
    $response = @file_get_contents($url, false, $context);

    if (
        is_null($response) ||
        count($http_response_header) === 0 ||
        explode(' ', $http_response_header[0])[1] !== "200"
    ) {
        return false; //fail
    }

    return $response;
}

function get_next_8p_atnd_event_data()
{
    // これ、未来が二個以上あったら破滅する
    $atnd_res_json = file_get_contents("http://api.atnd.org/events/?keyword_or=hachioji.pm&format=json&count=1&owner_nickname=uzulla");
    $data = json_decode($atnd_res_json, 1);

    $newest = strtotime($data['events'][0]['event']['started_at']);

    if ($newest < time()-10800) {
        return false;
    } else {
        return $data['events'][0]['event'];
    }
}
