<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\Tools;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use voku\helper\AntiXSS;
use function array_merge;
use function json_decode;
use function json_encode;
use function time;

/**
 *  TicketController
 */
final class TicketController extends BaseController
{
    /**
     * @throws Exception
     */
    public function ticket(ServerRequest $request, Response $response, array $args): ?ResponseInterface
    {
        if ($_ENV['enable_ticket'] !== true) {
            return null;
        }

        $tickets = Ticket::where('userid', $this->user->id)->orderBy('datetime', 'desc')->get();

        foreach ($tickets as $ticket) {
            $ticket->status = Tools::getTicketStatus($ticket);
            $ticket->type = Tools::getTicketType($ticket);
            $ticket->datetime = Tools::toDateTime((int) $ticket->datetime);
        }

        if ($request->getParam('json') === 1) {
            return $response->withJson([
                'ret' => 1,
                'tickets' => $tickets,
            ]);
        }

        return $response->write(
            $this->view()
                ->assign('tickets', $tickets)
                ->fetch('user/ticket/index.tpl')
        );
    }

    public function ticketAdd(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $title = $request->getParam('title');
        $comment = $request->getParam('comment');
        $type = $request->getParam('type');
        if ($title === '' || $comment === '') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '非法输入',
            ]);
        }

        $antiXss = new AntiXSS();

        $content = [
            [
                'comment_id' => 0,
                'commenter_name' => $this->user->user_name,
                'comment' => $antiXss->xss_clean($comment),
                'datetime' => time(),
            ],
        ];

        $ticket = new Ticket();
        $ticket->title = $antiXss->xss_clean($title);
        $ticket->content = json_encode($content);
        $ticket->userid = $this->user->id;
        $ticket->datetime = time();
        $ticket->status = 'open_wait_admin';
        $ticket->type = $antiXss->xss_clean($type);
        $ticket->save();

        if ($_ENV['mail_ticket'] === true) {
            $adminUser = User::where('is_admin', 1)->get();
            foreach ($adminUser as $user) {
                $user->sendMail(
                    $_ENV['appName'] . '-新工单被开启',
                    'news/warn.tpl',
                    [
                        'text' => '管理员，有人开启了新的工单，请您及时处理。',
                    ],
                    []
                );
            }
        }
        if ($_ENV['useScFtqq'] === true) {
            $ScFtqq_SCKEY = $_ENV['ScFtqq_SCKEY'];
            $postdata = http_build_query([
                'title' => $_ENV['appName'] . '-新工单被开启',
                'desp' => $title,
            ]);
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $postdata,
                ],
            ];
            $context = stream_context_create($opts);
            file_get_contents('https://sctapi.ftqq.com/' . $ScFtqq_SCKEY . '.send', false, $context);
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '提交成功',
        ]);
    }

    public function ticketUpdate(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $args['id'];
        $comment = $request->getParam('comment');

        if ($comment === '') {
            return $response->withJson([
                'ret' => 0,
                'msg' => '非法输入',
            ]);
        }

        $ticket = Ticket::where('id', $id)->where('userid', $this->user->id)->first();

        if ($ticket === null) {
            return $response->withStatus(302)->withHeader('Location', '/user/ticket');
        }

        $antiXss = new AntiXSS();

        $content_old = json_decode($ticket->content, true);
        $content_new = [
            [
                'comment_id' => $content_old[count($content_old) - 1]['comment_id'] + 1,
                'commenter_name' => $this->user->user_name,
                'comment' => $antiXss->xss_clean($comment),
                'datetime' => time(),
            ],
        ];

        $ticket->content = json_encode(array_merge($content_old, $content_new));
        $ticket->status = 'open_wait_admin';
        $ticket->save();

        if ($_ENV['mail_ticket'] === true) {
            $adminUser = User::where('is_admin', 1)->get();
            foreach ($adminUser as $user) {
                $user->sendMail(
                    $_ENV['appName'] . '-工单被回复',
                    'news/warn.tpl',
                    [
                        'text' => '管理员，有人回复了<a href="' . $_ENV['baseUrl'] . '/admin/ticket/' . $ticket->id . '/view">工</a>，请您及时处理。',
                    ],
                    []
                );
            }
        }
        if ($_ENV['useScFtqq'] === true) {
            $ScFtqq_SCKEY = $_ENV['ScFtqq_SCKEY'];
            $postdata = http_build_query([
                'title' => $_ENV['appName'] . '-工单被回复',
                'desp' => $ticket->title,
            ]);
            $opts = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $postdata,
                ],
            ];
            $context = stream_context_create($opts);
            file_get_contents('https://sctapi.ftqq.com/' . $ScFtqq_SCKEY . '.send', false, $context);
        }

        return $response->withJson([
            'ret' => 1,
            'msg' => '提交成功',
        ]);
    }

    /**
     * @throws Exception
     */
    public function ticketView(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $id = $args['id'];
        $ticket = Ticket::where('id', '=', $id)->where('userid', $this->user->id)->first();
        $comments = json_decode($ticket->content, true);

        $ticket->status = Tools::getTicketStatus($ticket);
        $ticket->type = Tools::getTicketType($ticket);
        $ticket->datetime = Tools::toDateTime((int) $ticket->datetime);

        if ($ticket === null) {
            if ($request->getParam('json') === 1) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '无访问权限',
                ]);
            }
            return $response->withStatus(302)->withHeader('Location', '/user/ticket');
        }
        if ($request->getParam('json') === 1) {
            return $response->withJson([
                'ret' => 1,
                'ticket' => $ticket,
            ]);
        }

        return $response->write(
            $this->view()
                ->assign('ticket', $ticket)
                ->assign('comments', $comments)
                ->registerClass('Tools', Tools::class)
                ->fetch('user/ticket/view.tpl')
        );
    }
}
