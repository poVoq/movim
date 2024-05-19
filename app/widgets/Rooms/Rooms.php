<?php

use Moxl\Xec\Action\Disco\Request;
use Moxl\Xec\Action\Presence\Muc;
use Moxl\Xec\Action\Presence\Unavailable;
use Moxl\Xec\Action\Muc\GetMembers;

use Movim\Session;
use Movim\ChatStates;
use Movim\Widget\Base;

use App\Conference;
use Movim\ChatroomPings;

class Rooms extends Base
{
    public function load()
    {
        $this->addcss('rooms.css');
        $this->addjs('rooms.js');

        $this->registerEvent('bookmark2_get_handle', 'onBookmarkGet');
        $this->registerEvent('bookmark2', 'onBookmarkSet');
        $this->registerEvent('bookmark2_set_handle', 'onBookmarkSet');
        $this->registerEvent('bookmark2_delete_handle', 'onBookmarkSet');

        $this->registerEvent('muc_destroy_handle', 'onDestroyed', 'chat');

        $this->registerEvent('chatstate', 'onChatState', 'chat');
        $this->registerEvent('message', 'onMessage');
        $this->registerEvent('presence_unavailable_handle', 'onDisconnected', 'chat');

        $this->registerEvent('presence_muc_handle', 'onConnected'/*, 'chat'*/);
        $this->registerEvent('presence_muc_errorconflict', 'onConflict');
        $this->registerEvent('presence_muc_errorregistrationrequired', 'onRegistrationRequired');
        $this->registerEvent('presence_muc_errorremoteservernotfound', 'onRemoteServerNotFound');
        $this->registerEvent('presence_muc_errorremoteservertimeout', 'onRemoteServerTimeout');
        $this->registerEvent('presence_muc_erroritemnotfound', 'onItemNotFound');
        $this->registerEvent('presence_muc_errornotauthorized', 'onNotAuthorized');
        $this->registerEvent('presence_muc_errorforbidden', 'onForbidden');
        $this->registerEvent('presence_muc_errorjidmalformed', 'onJidMalformed');
        $this->registerEvent('presence_muc_errornotacceptable', 'onNotAcceptable');
        $this->registerEvent('presence_muc_errorserviceunavailable', 'onServiceUnavailable');

        // Bug: In Chat::ajaxGet, Notif.current might come after this event
        // so we don't set the filter
        $this->registerEvent('chat_open_room', 'onChatOpen'/*, 'chat'*/);
    }

    public function onChatOpen(string $room)
    {
        $this->setCounter($room);
    }

    public function onChatState(array $array)
    {
        $this->setState($array[0], isset($array[1]));
    }

    public function onMessage($packet)
    {
        $message = $packet->content;

        if ($message->isMuc()) {
            $chatStates = ChatStates::getInstance();
            $chatStates->clearState($message->jidfrom, $message->resource);

            $this->onChatState($chatStates->getState($message->jidfrom));
            $this->setCounter($message->jidfrom);
        }
    }

    public function onDestroyed($packet)
    {
        $this->ajaxHttpGet();
        $this->rpc('Chat_ajaxGet');

        Toast::send($this->__('chatrooms.destroyed'));
    }

    public function onConnected($packet)
    {
        list($presence, $notify) = array_values($packet->content);
        $this->onPresence($presence->jid);
    }

    public function onDisconnected($packet)
    {
        if ($packet->content) {
            $this->onPresence($packet->content);
            Toast::send($this->__('chatrooms.disconnected'));
        }
    }

    public function onBookmarkGet($packet)
    {
        foreach ($this->user->session->conferences()
                      ->where('bookmarkversion', (int)$packet->content)
                      ->get() as $room) {
            if ($room->autojoin && !$room->connected) {
                $this->ajaxJoin($room->conference, $room->nick);
            }
        }

        $this->ajaxHttpGet();
    }

    public function onBookmarkSet($packet)
    {
        $conference = $packet->content;

        if ($conference && $conference->autojoin) {
            $this->ajaxJoin($conference->conference, $conference->nick);
        }

        Toast::send($this->__('bookmarks.updated'));

        if ($conference) {
            $this->onPresence($conference->conference);
        } else {
            $this->rpc('Rooms_ajaxHttpGet');
        }
    }

    private function setState(string $room, bool $composing)
    {
        $this->rpc(
            $composing
                ? 'MovimUtils.addClass'
                : 'MovimUtils.removeClass',
            '#' . cleanupId($room.'_rooms_primary'),
            'composing'
        );
    }

    private function setCounter(string $room)
    {
        $conference = $this->user->session
                           ->conferences()
                           ->where('conference', $room)
                           ->withCount('unreads', 'quoted')
                           ->first();

        if ($conference) {
            $this->rpc(
                'MovimTpl.fill',
                '#' . cleanupId($room.'_rooms_primary'),
                $this->prepareRoomCounter($conference, $conference->getPicture())
            );

            $unread = ($conference->unreads_count > 0 || $conference->quoted_count > 0);
            $this->rpc('Rooms.setUnread', cleanupId($room), $unread);
        }
    }

    private function onPresence(string $room)
    {
        $conference = $this->user->session->conferences()
                                          ->where('conference', $room)
                                          ->with('info', 'contact', 'presence')
                                          ->withCount('unreads', 'quoted', 'presences')
                                          ->first();

        if ($conference) {
            $this->rpc('Rooms.setRoom', \cleanupId($conference->conference), $this->prepareConference($conference));
        }
    }

    public function ajaxHttpGet()
    {
        $conferences = $this->user->session->conferences()
                                           ->with('info', 'contact', 'presence')
                                           ->withCount('unreads', 'quoted', 'presences')
                                           ->get();

        $this->rpc('Rooms.clearRooms');

        foreach ($conferences as $conference) {
            $this->rpc('Rooms.setRoom', \cleanupId($conference->conference), $this->prepareConference($conference));
        }

        $this->rpc('Rooms.refresh');
        $this->rpc('Rooms.checkNoConnected');

        $this->rpc('MovimUtils.removeClass', '#rooms_widget ul.list.rooms', 'spin');

        $view = $this->tpl();
        $this->rpc('MovimTpl.remove', '#rooms_widget ul.list.empty');
        $this->rpc('MovimTpl.appendAfter', '#rooms_widget ul.list.rooms', $view->draw('_rooms_empty'));
    }

    /**
     * @brief Join a chatroom
     */
    public function ajaxJoin(string $room, ?string $nickname = null)
    {
        if (!validateRoom($room)) {
            return;
        }

        $r = new Request;
        $r->setTo($room)
          ->request();

        $p = new Muc;
        $p->setTo($room);

        if ($nickname == null) {
            $nickname = $this->user->username;
        }

        $jid = explodeJid($room);
        $capability = \App\Info::where('server', $jid['server'])
                               ->where('node', '')
                               ->first();

        if ($capability && ($capability->isMAM() || $capability->isMAM2())) {
            $this->rpc('MovimUtils.addClass', '#chat_widget .contained', 'loading');

            $p->enableMAM();

            if ($capability->isMAM2()) {
                $p->enableMAM2();
            }
        } else {
            $r = new Request;
            $r->setTo($jid['server'])
              ->request();
        }

        $m = new GetMembers;
        $m->setTo($room)
          ->request();

        $p->setNickname($nickname);
        $p->request();
    }

    /**
     * @brief Exit a room
     *
     * @param string $room
     */
    public function ajaxExit($room)
    {
        if (!validateRoom($room)) {
            return;
        }

        // We reset the Chat view
        $this->rpc('Chat_ajaxGet');

        // We properly exit
        $conference = $this->user->session->conferences()
            ->where('conference', $room)
            ->first();

        if (!$conference) return;

        $resource = $conference->presence?->resource;

        $jid = explodeJid($room);
        $capability = \App\Info::where('server', $jid['server'])
                               ->where('node', '')
                               ->first();

        if (!$capability || !$capability->isMAM()) {
            $this->user->messages()->where('jidfrom', $room)->delete();
        }

        // We clear the ping timer
        ChatroomPings::getInstance()->clear($room);

        // We clear the presences from the buffer cache and then the DB
        $this->user->session->conferences()
             ->where('conference', $room)
             ->first()->presences()->delete();

        $this->ajaxHttpGet();

        if ($resource) {
            $session = Session::start();
            $session->delete($room . '/' .$resource);

            $pu = new Unavailable;
            $pu->setTo($room)
               ->setResource($resource)
               ->request();
        }
    }

    public function prepareConference(Conference $conference)
    {
        $view = $this->tpl();
        $view->assign('conference', $conference);

        return $view->draw('_rooms_room');
    }

    public function prepareRoomCounter(Conference $conference, $withAvatar = false)
    {
        $view = $this->tpl();
        $view->assign('conference', $conference);
        $view->assign('withAvatar', $withAvatar);

        return $view->draw('_rooms_counter');
    }

    /**
     * Join errors
     */

    public function onConflict()
    {
        Toast::send($this->__('chatrooms.conflict'));
    }

    public function onRegistrationRequired($packet)
    {
        Toast::send($this->__('chatrooms.registrationrequired'));
        $this->ajaxExit($packet->content);
    }

    public function onRemoteServerNotFound($packet)
    {
        Toast::send($this->__('chatrooms.remoteservernotfound'));
        $this->ajaxExit($packet->content);
    }

    public function onRemoteServerTimeout($packet)
    {
        Toast::send($this->__('chatrooms.remoteservertimeout'));
        $this->ajaxExit($packet->content);
    }

    public function onItemNotFound($packet)
    {
        Toast::send($this->__('chatrooms.itemnotfound'));
        $this->ajaxExit($packet->content);
    }

    public function onNotAuthorized($packet)
    {
        Toast::send($this->__('chatrooms.notauthorized'));
        $this->ajaxExit($packet->content);
    }

    public function onForbidden($packet)
    {
        Toast::send($this->__('chatrooms.forbidden'));
        $this->ajaxExit($packet->content);
    }

    public function onJidMalformed($packet)
    {
        Toast::send($this->__('chatrooms.jidmalformed'));
        $this->ajaxExit($packet->content);
    }

    public function onNotAcceptable($packet)
    {
        Toast::send($this->__('chatrooms.notacceptable'));
        $this->ajaxExit($packet->content);
    }

    public function onServiceUnavailable($packet)
    {
        Toast::send($this->__('chatrooms.serviceunavailable'));
        $this->ajaxExit($packet->content);
    }
}
