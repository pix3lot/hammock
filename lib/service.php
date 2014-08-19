<?php
	class SlackServicePlugin {

		public $name = "NO NAME";
		public $desc = "NO DESC";

		public $id;	# class ID
		public $iid;	# instance ID

		public $cfg;	# class config
		public $icfg;	# instance config

		private $log = array();

		function SlackServicePlugin(){
			if ($this->name == "NO NAME"){
				$cn = get_class($this);
				$this->name = "Unnamed ({$cn})";
			}
		}

		function createInstanceId(){
			$this->iid = uniqid();
		}

		function setInstanceConfig($iid, $icfg){
			$this->iid = $iid;
			$this->icfg = $icfg;
		}

		function checkRequirements(){

			if ($this->cfg['requires_auth']){
				$auth_plugin = $this->cfg['requires_auth'];
				$auth = getAuthPlugin($auth_plugin);
				if (!$auth->isConfigured()) die("This plugin requires auth be configured - {$auth_plugin}");
				if (!$auth->isUserAuthed()) die("You need to authenticate before continuing");
			}
		}

		function getHookUrl(){

			$url =  $GLOBALS['cfg']['root_url'] . 'hook.php?id=' . $this->iid;

			if ($this->cfg['has_token']) $url .= "&token={$this->icfg['token']}";

			return $url;
		}

		function getEditUrl(){

			return $GLOBALS['cfg']['root_url'] . 'edit.php?id=' . $this->iid;
		}

		function getViewUrl(){

			return $GLOBALS['cfg']['root_url'] . 'view.php?id=' . $this->iid;
		}

		function dump(){
			$s = $this->smarty;
			unset($this->smarty);
			dumper($this);
			$this->smarty = $s;
		}

                function saveConfig(){
			$cfg = $this->icfg;
			$cfg['plugin'] = $this->id;
			$GLOBALS['data']->set('instances', $this->iid, $cfg);
		}

		function deleteMe(){
			$cfg = $GLOBALS['data']->get('instances', $this->iid);
			$GLOBALS['data']->set('deleted_instances', $this->iid, $cfg);
			$GLOBALS['data']->del('instances', $this->iid);
		}

		function postToChannel($text, $extra){

			$this->log[] = array(
				'type' => 'message_post',
				'text' => $text,
				'extra' => $extra,
			);

			$params = array(
				'text'		=> $text,
				'parse'		=> 'none',
				'channel'	=> '#general',
				'icon_url'	=> $this->iconUrl(48, true),
			);

			$map_params = array(
				'channel',
				'username',
				'attachments',
				'unfurl_links',
				'icon_url',
				'icon_emoji',
			);

			foreach ($map_params as $p){
				if (!empty($extra[$p])){
					if ($p == 'attachments'){
						$params[$p] = json_encode($extra[$p]);
					}else{
						$params[$p] = $extra[$p];
					}
				}
			}
			echo "params pre-api call";
			dumper($params);
			$ret = api_call('chat.postMessage', $params);
			echo "ret post-api call";
			dumper($ret);
			return $ret;
		}

		function getLog(){
			return $this->log;
		}

		function escapeText($str){
			return HtmlSpecialChars($str, ENT_NOQUOTES);
		}

		function escapeLink($url, $label=null){
			$url = trim($url);

			$url = $this->escapeText($url);
			$url = str_replace('|', '%7C', $url);

			if (strlen($label)){

				$label = $this->escapeText($label);

				return "<{$url}|{$label}>";
			}

			return "<{$url}>";
		}

		function onParentInit(){

			if ($this->cfg['has_token']){
				$this->regenToken();
			}
		}

		function regenToken(){

			$this->icfg['token'] = substr(sha1(rand()), 1, 10);
		}

		function getChannelsList(){

			return api_channels_list();
		}

		function onLiveHook($req){

			if ($this->cfg['has_token']){
				if ($req['get']['token'] != $this->icfg['token']){
					return array(
						'ok'		=> false,
						'error'		=> 'bad_token',
						'sent'		=> $req['get']['token'],
						'expected'	=> $this->icfg['token'],
					);
				}
			}

			return $this->onHook($req);
		}


		# things to override

		function onView(){

			return "<p>No information for this plugin.</p>";
		}

		function onEdit(){

			return "<p>No config for this plugin.</p>";
		}

		function getLabel(){

			return "No label ({$this->iid})";
		}

		function onInit(){
			# set default options in $this->icfg here
		}

		function onHook($request){
			# handle an incoming hook here
			return array(
				'ok'	=> false,
				'error'	=> 'onHook not implemented',
			);
		}

		function iconUrl($size=32, $abs=false){
			if (!in_array($size, array(32,48,64,128))) $size = 32;
			$pre = $abs ? $GLOBALS['cfg']['root_url'] : '';
			return "{$pre}plugins/{$this->id}/icon_{$size}.png";
		}
	}

	class SlackSimpleServicePlugin extends SlackServicePlugin {

		function onInit() {
		    $channels = $this->getChannelsList();

		    foreach ($channels as $k => $v) {
		        if ($v == '#testinghammock') {
		            $this->icfg['channel']      = $k;
		            $this->icfg['channel_name'] = $v;
		        }
		    }

		    $this->icfg['botname'] = $name;
		    $this->icfg['icon_url'] = trim($GLOBALS['cfg']['root_url'], '/') . '/plugins/' . $this->id . '/icon_48.png';
		}

		function onView() {
		    return $this->smarty->fetch('view.txt');
		}

		function onEdit() {
		    $channels = $this->getChannelsList();
		    if ($_GET['save']) {
		        $this->icfg['channel']      = $_POST['channel'];
		        $this->icfg['channel_name'] = $channels[$_POST['channel']];
		        $this->icfg['botname']      = $_POST['botname'];
		        $this->saveConfig();

		        header("location: {$this->getViewUrl()}&saved=1");
		        exit;
		    }
		    $this->smarty->assign('channels', $channels);
		    return $this->smarty->fetch('edit.txt');
		}

		function onHook($request){

			if ($request['post']['payload']) {
				$payload = json_decode($request['post']['payload'], true);
			} else {
				$payload = json_decode($request['post_body'], true);
			}

			if (!$payload){
				return array('ok' => false, 'error' => "invalid_payload");
			}
			$message = $payload['text'];
			$attachments = $this->defaultMessageFilter($payload);

			$this->postToChannel($message, array(
	            'channel'		=> $this->icfg['channel'],
	            'username'		=> $this->icfg['botname'],
	            'attachments'	=> $attachments,
	            'icon_url'		=> $this->icfg['icon_url'],
	        ));
		}

		function getLabel() {
        	return "Post to {$this->icfg['channel_name']} as {$this->icfg['botname']}";
    	}

		#
		# This filters an incoming payload to only include the fields we
		# support in simple plugins. The contents of the fields are filtered
		# by postMessage when posting the message to channel.
		#
		private function defaultMessageFilter($payload){
			$out = array();

			if($payload['attachments']) {
				$out['attachments'] = array();
				foreach ($payload['attachments'] as $attach){
					if (!is_array($attach)) continue;
					$clean = array();
					$fields = array(
						'fallback',
						'text',
						'pretext',
						'title',
						'color',
						'fields',
						'mrkdwn_in'
					);

					foreach ($fields as $field){
						if (isset($attach[$field])) $clean[$field] = $attach[$field];
					}
					$out['attachments'][] = $clean;
				}
			}

			return $out;
		}
	}

