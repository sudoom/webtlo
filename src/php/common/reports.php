<?php

use KeepersTeam\Webtlo\AppContainer;
use KeepersTeam\Webtlo\Config\Validate as ConfigValidate;
use KeepersTeam\Webtlo\Enum\UpdateMark;
use KeepersTeam\Webtlo\Forum\Report\Creator as ReportCreator;
use KeepersTeam\Webtlo\Forum\SendReport;
use KeepersTeam\Webtlo\Legacy\Db;
use KeepersTeam\Webtlo\Module\Forums;
use KeepersTeam\Webtlo\Module\LastUpdate;
use KeepersTeam\Webtlo\Timers;

include_once dirname(__FILE__) . '/../../vendor/autoload.php';
include_once dirname(__FILE__) . '/../classes/reports.php';
include_once dirname(__FILE__) . '/../classes/user_details.php';

$app = AppContainer::create('reports.log');
$log = $app->getLogger();

Timers::start('send_reports');
$log->info('Начат процесс отправки отчётов...');

// Получение настроек.
$cfg = $app->getLegacyConfig();

if (isset($checkEnabledCronAction)) {
    $checkEnabledCronAction = $cfg['automation'][$checkEnabledCronAction] ?? -1;
    if ($checkEnabledCronAction == 0) {
        throw new Exception('Notice: Автоматическая отправка отчётов отключена в настройках.');
    }
}

// Проверка настроек.
$user = ConfigValidate::checkUser($cfg);
if (empty($cfg['subsections'])) {
    $log->error('Не выбраны хранимые подразделы');

    return;
}

// Проверим полное обновление.
LastUpdate::checkReportsSendAvailable($cfg);


// Подключаемся к API отчётов.
Timers::start('create_api');

/** @var SendReport $sendReport */
$sendReport = $app->get(SendReport::class);

// Желание отправить отчёт через API.
$sendReport->setEnable((bool)($cfg['reports']['send_report_api'] ?? true));

// Проверяем доступность API.
if ($sendReport->isEnable()) {
    $sendReport->checkAccess();

    $log->debug('create api {sec}', ['sec' => Timers::getExecTime('create_api')]);
}

if (!$sendReport->isEnable()) {
    $log->info('Отправка отчёта в API невозможна или отключена');
}


Timers::start('init_report');

// Желание отправить отчёты на форум.
$sendForumReports = (bool)($cfg['reports']['send_report_forum'] ?? true);

// Желание отправить сводный отчёт на форум.
$sendForumSummary = (bool)($cfg['reports']['send_summary_report'] ?? true);

$forceCleanForum = true;
if ($sendForumReports) {
    $forceCleanForum  = false;

    $revolution = $sendReport->checkRevolution();
    if (null !== $revolution) {
        $revolution = $revolution->format('d.m.Y');
        if ($sendReport->isRevolutionStarted()) {
            $log->warning('Отправка отчётов на форум невозможна. Внесите изменения в настройки отправки отчётов.');

            $forceCleanForum  = true;
            $sendForumReports = false;
        } else {
            $log->notice(
                'Внимание, отправка отчётов на форум будет заблокирована, начиная с {date}. Для ускорения работы программы снимите в настройках галочку "Отправлять отчеты на форум".',
                ['date' => $revolution]
            );
        }
    }
}


// Проверим заполненность таблиц.
if ($sendForumReports && Db::select_count('ForumsOptions') === 0) {
    $log->error(
        'Отправка отчётов невозможна. Отсутствуют сведения о сканировании подразделов. Выполните полное обновление сведений.'
    );
    $sendForumReports = false;
}

// Возможность отправить любые отчёты на форум.
$forumReportAvailable = $sendForumReports || $sendForumSummary;

if ($forumReportAvailable) {
    // Подключаемся к форуму.
    if (!isset($reports)) {
        $reports = new Reports(
            $cfg['forum_address'],
            $user,
        );
        // применяем таймауты
        $reports->curl_setopts($cfg['curl_setopt']['forum']);
    }

    if ($unavailable = $reports->check_access()) {
        $log->error($unavailable->value);
        $forumReportAvailable = false;
    }
}
$log->debug('init report {sec}', ['sec' => Timers::getExecTime('init_report')]);

Timers::start('create_report');
// Создание отчётов.
$forumReports = new ReportCreator(
    $cfg,
    $user
);
$forumReports->initConfig();
if ($forumReportAvailable) {
    $forumReports->fillStoredValues();
}

$log->debug('create report {sec}', ['sec' => Timers::getExecTime('create_report')]);


$forumReportCount = 0;
$apiReportCount   = 0;

$editedTopicsIDs = [];
$editedPosts     = [];

$Timers = [];

$forumCount = $forumReports->getForumCount();

$forumsToReport = [];
foreach ($forumReports->forums as $forum_id) {
    $timer = [];

    // Пробуем отправить отчёт по API.
    if ($sendReport->isEnable() && !$forumReports->isForumExcluded($forum_id)) {
        $forumsToReport[] = $forum_id;

        Timers::start("send_api_$forum_id");
        try {
            Timers::start("search_db_$forum_id");

            // Получаем раздачи, которые нужно отправить.
            $topicsToReport = $forumReports->getStoredForumTopics($forum_id);

            $timer['search_db'] = Timers::getExecTime("search_db_$forum_id");

            // Пробуем отправить отчёт по API.
            $apiResult = $sendReport->sendForumTopics((int)$forum_id, $topicsToReport);

            $timer['send_api'] = Timers::getExecTime("send_api_$forum_id");

            $log->debug(
                'API. Отчёт отправлен [{current}/{total}] {sec}',
                ['current' => ++$apiReportCount, 'total' => $forumCount, 'sec' => $timer['send_api'], ...$apiResult]
            );
        } catch (Exception $e) {
            $log->notice(
                'Попытка отправки отчёта через API для подраздела {forum_id} не удалась. Причина {error}',
                ['forum_id' => $forum_id, 'error' => $e->getMessage()]
            );
        }
    }

    // Пробуем отправить отчёт на форум.
    if ($forumReportAvailable && $sendForumReports && isset($reports)) {
        Timers::start("report_forum_$forum_id");
        try {
            $forum = Forums::getForum($forum_id);
            if (null === $forum->topic_id) {
                $log->notice(
                    'Отсутствует номер темы со списками для подраздела {forumId}. Выполните обновление сведений.',
                    ['forumId' => $forum_id]
                );
                continue;
            }

            Timers::start("create_$forum_id");
            $forumReport = $forumReports->getForumReport($forum);
        } catch (Exception $e) {
            $log->warning(
                'Формирование отчёта для подраздела {forumId} пропущено. Причина {error}',
                ['forumId' => $forum_id, 'error' => $e->getMessage()]
            );
            continue;
        }

        $createTime = Timers::getExecTime("create_$forum_id");

        Timers::start("send_$forum_id");
        $topicId = $forum->topic_id;
        $messages = $forumReport['messages'];// Редактируем шапку темы, если её автор - пользователь.
        if ($user->userId === $forum->author_id && $forum->author_post_id && !empty($forumReport['header'])) {
            $log->info(
                'Отправка шапки, ид темы {topicId}, ид сообщения {postId}',
                ['topicId' => $topicId, 'postId' => $forum->author_post_id]
            );
            // отправка сообщения с шапкой
            $reports->send_message(
                'editpost',
                $forumReport['header'],
                $topicId,
                $forum->author_post_id,
                '[Список] ' . $forum->name
            );
            usleep(500);
        }

        // Вставка дополнительных сообщений в тему.
        $postList = $forum->post_ids ?? [];
        if (count($messages) > count($postList)) {
            $count_post_reply = count($messages) - count($postList);
            for ($i = 1; $i <= $count_post_reply; $i++) {
                $message = '[spoiler]' . $i . str_repeat('?', 119981 - mb_strlen((string)$i)) . '[/spoiler]';
                $post_id = $reports->send_message(
                    'reply',
                    $message,
                    $topicId
                );
                if ($post_id > 0) {
                    $postList[] = (int)$post_id;
                }
                usleep(500);

                unset($message, $post_id);
            }
            if ($count_post_reply > 0) {
                Forums::updatePostList($forum_id, $postList);
            }
            unset($count_post_reply);
        }

        // редактирование сообщений
        foreach ($postList as $index => $postId) {
            $post_number = $index + 1;
            $message     = $messages[$index] ?? 'резерв';
            $reports->send_message(
                'editpost',
                $message,
                $topicId,
                $postId
            );
            $editedPosts[$topicId][] = $postId;

            unset($index, $postId, $post_number, $message);
        }

        $editedTopicsIDs[] = $topicId;

        $timer['send_forum'] = Timers::getExecTime("send_forum_$forum_id");

        $log->debug(
            'Forum. Отчёт отправлен [{current}/{total}] {sec}',
            ['current' => ++$forumReportCount, 'total' => $forumCount, 'sec' => $timer['send_forum']]
        );
    }

    $forumReports->clearCache($forum_id);
    $Timers[] = ['forum' => $forum_id, ...$timer];
}

if (count($forumsToReport) && $sendReport->isEnable()) {
    $unsetOtherForums = (bool)($cfg['reports']['unset_other_forums'] ?? true);

    $setStatus = $sendReport->setForumsStatus($forumsToReport, $unsetOtherForums);
    $log->debug('kept forums setStatus', $setStatus);
}

if (!empty($Timers)) {
    $log->debug(json_encode($Timers));
}

if ($apiReportCount > 0) {
    $log->info('Отчётов отправлено в API: {count} шт.', ['count' => $apiReportCount]);
}

if (count($editedTopicsIDs)) {
    $log->info('Отчётов отправлено на форум: {count} шт.', ['count' => count($editedTopicsIDs)]);
    $log->debug(json_encode($editedPosts));
}


if ($forumReportAvailable && isset($reports)) {
    // Затираем более ненужные списки на форуме.
    if ($forceCleanForum) {
        foreach ($forumReports->forums as $forumId) {
            $forumDetails = Forums::getForum($forumId);
            if (empty($forumDetails->topic_id) || empty($forumDetails->post_ids)) {
                continue;
            }

            // Пометим свои посты как "не актуальные".
            $tmpPostList = [];
            foreach ($forumDetails->post_ids as $postId) {
                $clearPostResult = $reports->send_message('editpost', ':!: не актуально', $forumDetails->topic_id, $postId);

                // Если отредактировать пост не удалось, то скорее всего его уже отправили в архив.
                if (empty($clearPostResult)) {
                    $tmpPostList[] = $postId;
                }
            }
            $editedTopicsIDs[] = $forumDetails->topic_id;

            // Стираем в локальной БД несуществующие посты.
            if (count($tmpPostList)) {
                $tmpPostList = array_values(array_diff($forumDetails->post_ids, $tmpPostList));
                Forums::updatePostList($forumId, $tmpPostList);
            }
        }
    }

    // Отправим сводный отчёт, даже если отправка обычных отчётов на форум отключена.
    if ($sendForumSummary) {
        Timers::start('send_summary');
        // формируем сводный отчёт
        $summaryReport = $forumReports->getSummaryReport();

        // ищем сообщение со сводным
        $summaryPostId = $reports->search_post_id(ReportCreator::SUMMARY_FORUM, true);

        $summaryPostMode = empty($summaryPostId) ? 'reply' : 'editpost';
        // отправляем сводный отчёт
        $reports->send_message(
            $summaryPostMode,
            $summaryReport,
            ReportCreator::SUMMARY_FORUM,
            $summaryPostId
        );

        // Запишем ид темы со сводными, чтобы очистка сообщений не задела.
        $editedTopicsIDs[] = ReportCreator::SUMMARY_FORUM;

        // Запишем время отправки отчётов.
        LastUpdate::setTime(UpdateMark::SEND_REPORT->value);

        $log->info('Отправка сводного отчёта завершена за {sec}', ['sec' => Timers::getExecTime('send_summary')]);
    }

    // Если ни одного отчёта по разделу не отправлено на форум, завершаем работу.
    if (!count($editedTopicsIDs)) {
        return;
    }

    // отредактируем все сторонние темы со своими сообщениями в рабочем подфоруме
    if ($cfg['reports']['auto_clear_messages']) {
        $emptyMessages = [];

        $topicsIDsWithMyMessages = $reports->searchTopicsIDs(['uid' => $user->userId]);

        $uneditedTopicsIDs = array_diff($topicsIDsWithMyMessages, $editedTopicsIDs);
        if (!empty($uneditedTopicsIDs)) {
            foreach ($uneditedTopicsIDs as $topicID) {
                $messages = $reports->scanning_viewtopic($topicID);
                if ($messages === false) {
                    continue;
                }
                foreach ($messages as $index => $message) {
                    // пропускаем шапку
                    if ($index == 0) {
                        continue;
                    }
                    // только свои сообщения
                    if ($user->userId === $message['user_id']) {
                        $emptyMessages[] = $message['post_id'];
                        $reports->send_message('editpost', ':!: не актуально', $topicID, $message['post_id']);
                    }
                }
            }
        }

        if (count($emptyMessages)) {
            $log->info(
                'Помечено неактуальных сообщений: {count} => {messages}',
                ['count' => count($emptyMessages), 'messages' => implode(', ', $emptyMessages)]
            );
        }
    }
}

$log->info('Процесс отправки отчётов завершён за {sec}', ['sec' => Timers::getExecTime('send_reports')]);
