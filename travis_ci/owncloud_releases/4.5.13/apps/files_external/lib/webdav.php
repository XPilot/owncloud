<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

class OC_FileStorage_DAV extends OC_Filestorage_Common{
	private $password;
	private $user;
	private $host;
	private $secure;
	private $root;
	/**
	 * @var Sabre_DAV_Client
	 */
	private $client;

	private static $tempFiles=array();

	public function __construct($params) {
		$host = $params['host'];
		//remove leading http[s], will be generated in createBaseUri()
		if (substr($host,0,8) == "https://") $host = substr($host, 8);
		else if (substr($host,0,7) == "http://") $host = substr($host, 7);
		$this->host=$host;
		$this->user=$params['user'];
		$this->password=$params['password'];
		if(isset($params['secure'])){
			if(is_string($params['secure'])){
				$this->secure = ($params['secure'] === 'true');
			}else{
				$this->secure = (bool)$params['secure'];
			}
		}else{
			$this->secure = false;
		}
		$this->root=isset($params['root'])?$params['root']:'/';
		if(!$this->root || $this->root[0]!='/') {
			$this->root='/'.$this->root;
		}
		if(substr($this->root,-1,1)!='/') {
			$this->root.='/';
		}

		$settings = array(
			'baseUri' => $this->createBaseUri(),
			'userName' => $this->user,
			'password' => $this->password,
		);

		$this->client = new OC_Connector_Sabre_Client($settings);

		if($caview = \OCP\Files::getStorage('files_external')) {
			$certPath=\OCP\Config::getSystemValue('datadirectory').$caview->getAbsolutePath("").'rootcerts.crt';
			if (file_exists($certPath))  {
				$this->client->addTrustedCertificates($certPath);
			}
		}
		//create the root folder if necesary
		$this->mkdir('');
	}

	private function createBaseUri() {
		$baseUri='http';
		if($this->secure) {
			$baseUri.='s';
		}
		$baseUri.='://'.$this->host.$this->root;
		return $baseUri;
	}

	public function mkdir($path) {
		$path=$this->cleanPath($path);
		return $this->simpleResponse('MKCOL',$path,null,201);
	}

	public function rmdir($path) {
		$path=$this->cleanPath($path);
		return $this->simpleResponse('DELETE',$path,null,204);
	}

	public function opendir($path) {
		$path=$this->cleanPath($path);
		try{
			$response=$this->client->propfind($path, array(),1);
			$id=md5('webdav'.$this->root.$path);
			OC_FakeDirStream::$dirs[$id]=array();
			$files=array_keys($response);
			array_shift($files);//the first entry is the current directory
			foreach($files as $file) {
				$file = urldecode(basename($file));
				OC_FakeDirStream::$dirs[$id][]=$file;
			}
			return opendir('fakedir://'.$id);
		}catch(Exception $e) {
			return false;
		}
	}

	public function filetype($path) {
		$path=$this->cleanPath($path);
		try{
			$response=$this->client->propfind($path, array('{DAV:}resourcetype'));
			$responseType=$response["{DAV:}resourcetype"]->resourceType;
			return (count($responseType)>0 and $responseType[0]=="{DAV:}collection")?'dir':'file';
		}catch(Exception $e) {
			error_log($e->getMessage());
			\OCP\Util::writeLog("webdav client", \OCP\Util::sanitizeHTML($e->getMessage()), \OCP\Util::ERROR);
			return false;
		}
	}

	public function isReadable($path) {
		return true;//not properly supported
	}

	public function isUpdatable($path) {
		return true;//not properly supported
	}

	public function file_exists($path) {
		$path=$this->cleanPath($path);
		try{
			$this->client->propfind($path, array('{DAV:}resourcetype'));
			return true;//no 404 exception
		}catch(Exception $e) {
			return false;
		}
	}

	public function unlink($path) {
		return $this->simpleResponse('DELETE',$path,null,204);
	}

	public function fopen($path,$mode) {
		$path=$this->cleanPath($path);
		switch($mode) {
			case 'r':
			case 'rb':
				if(!$this->file_exists($path)) {
					return false;
				}
				//straight up curl instead of sabredav here, sabredav put's the entire get result in memory
				$curl = curl_init();
				$fp = fopen('php://temp', 'r+');
				curl_setopt($curl,CURLOPT_USERPWD,$this->user.':'.$this->password);
				curl_setopt($curl, CURLOPT_URL, $this->createBaseUri().$path);
				curl_setopt($curl, CURLOPT_FILE, $fp);

				curl_exec ($curl);
				curl_close ($curl);
				rewind($fp);
				return $fp;
			case 'w':
			case 'wb':
			case 'a':
			case 'ab':
			case 'r+':
			case 'w+':
			case 'wb+':
			case 'a+':
			case 'x':
			case 'x+':
			case 'c':
			case 'c+':
				//emulate these
				if(strrpos($path,'.')!==false) {
					$ext=substr($path,strrpos($path,'.'));
				}else{
					$ext='';
				}
				$tmpFile=OCP\Files::tmpFile($ext);
				OC_CloseStreamWrapper::$callBacks[$tmpFile]=array($this,'writeBack');
				if($this->file_exists($path)) {
					$this->getFile($path,$tmpFile);
				}
				self::$tempFiles[$tmpFile]=$path;
				return fopen('close://'.$tmpFile,$mode);
		}
	}

	public function writeBack($tmpFile) {
		if(isset(self::$tempFiles[$tmpFile])) {
			$this->uploadFile($tmpFile,self::$tempFiles[$tmpFile]);
			unlink($tmpFile);
		}
	}

	public function free_space($path) {
		$path=$this->cleanPath($path);
		try{
			$response=$this->client->propfind($path, array('{DAV:}quota-available-bytes'));
			if(isset($response['{DAV:}quota-available-bytes'])) {
				return (int)$response['{DAV:}quota-available-bytes'];
			}else{
				return 0;
			}
		}catch(Exception $e) {
			return 0;
		}
	}

	public function touch($path,$mtime=null) {
		if(is_null($mtime)) {
			$mtime=time();
		}
		$path=$this->cleanPath($path);
		$this->client->proppatch($path, array('{DAV:}lastmodified' => $mtime));
	}

	public function getFile($path,$target) {
		$source=$this->fopen($path,'r');
		file_put_contents($target,$source);
	}

	public function uploadFile($path,$target) {
		$source=fopen($path,'r');

		$curl = curl_init();
		curl_setopt($curl,CURLOPT_USERPWD,$this->user.':'.$this->password);
		curl_setopt($curl, CURLOPT_URL, $this->createBaseUri().$target);
		curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
		curl_setopt($curl, CURLOPT_INFILE, $source); // file pointer
		curl_setopt($curl, CURLOPT_INFILESIZE, filesize($path));
		curl_setopt($curl, CURLOPT_PUT, true);
		curl_exec ($curl);
		curl_close ($curl);
	}

	public function rename($path1,$path2) {
		$path1=$this->cleanPath($path1);
		$path2=$this->root.$this->cleanPath($path2);
		try{
			$response=$this->client->request('MOVE',$path1,null,array('Destination'=>$path2));
			return true;
		}catch(Exception $e) {
			echo $e;
			echo 'fail';
			var_dump($response);
			return false;
		}
	}

	public function copy($path1,$path2) {
		$path1=$this->cleanPath($path1);
		$path2=$this->root.$this->cleanPath($path2);
		try{
			$response=$this->client->request('COPY',$path1,null,array('Destination'=>$path2));
			return true;
		}catch(Exception $e) {
			echo $e;
			echo 'fail';
			var_dump($response);
			return false;
		}
	}

	public function stat($path) {
		$path=$this->cleanPath($path);
		try{
			$response=$this->client->propfind($path, array('{DAV:}getlastmodified','{DAV:}getcontentlength'));
			return array(
				'mtime'=>strtotime($response['{DAV:}getlastmodified']),
				'size'=>(int)isset($response['{DAV:}getcontentlength']) ? $response['{DAV:}getcontentlength'] : 0,
				'ctime'=>-1,
			);
		}catch(Exception $e) {
			return array();
		}
	}

	public function getMimeType($path) {
		$path=$this->cleanPath($path);
		try{
			$response=$this->client->propfind($path, array('{DAV:}getcontenttype','{DAV:}resourcetype'));
			$responseType=$response["{DAV:}resourcetype"]->resourceType;
			$type=(count($responseType)>0 and $responseType[0]=="{DAV:}collection")?'dir':'file';
			if($type=='dir') {
				return 'httpd/unix-directory';
			}elseif(isset($response['{DAV:}getcontenttype'])) {
				return $response['{DAV:}getcontenttype'];
			}else{
				return false;
			}
		}catch(Exception $e) {
			return false;
		}
	}

	private function cleanPath($path) {
		if(!$path || $path[0]=='/') {
			return substr($path,1);
		}else{
			return $path;
		}
	}

	private function simpleResponse($method,$path,$body,$expected) {
		$path=$this->cleanPath($path);
		try{
			$response=$this->client->request($method,$path,$body);
			return $response['statusCode']==$expected;
		}catch(Exception $e) {
			return false;
		}
	}
}
