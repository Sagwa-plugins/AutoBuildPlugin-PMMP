<?php

/*
 *
 *  ____                           _   _  ___
 * |  _ \ _ __ ___  ___  ___ _ __ | |_| |/ (_)_ __ ___
 * | |_) | '__/ _ \/ __|/ _ \ '_ \| __| ' /| | '_ ` _ \
 * |  __/| | |  __/\__ \  __/ | | | |_| . \| | | | | | |
 * |_|   |_|  \___||___/\___|_| |_|\__|_|\_\_|_| |_| |_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the MIT License. see <https://opensource.org/licenses/MIT>.
 *
 * @author  PresentKim (debe3721@gmail.com)
 * @link    https://github.com/PresentKim
 * @license https://opensource.org/licenses/MIT MIT License
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 */

declare(strict_types=1);

namespace kim\present\autobuildplugin;

use kim\present\autobuildplugin\util\Utils;
use pocketmine\plugin;

class AutoBuildPlugin extends plugin\PluginBase{
	/** @var AutoBuildPlugin */
	private static $instance;

	/**
	 * @return AutoBuildPlugin
	 */
	public static function getInstance() : AutoBuildPlugin{
		return self::$instance;
	}

	/**
	 * Called when the plugin is loaded, before calling onEnable()
	 */
	public function onLoad() : void{
		self::$instance = $this;
	}

	/**
	 * Called when the plugin is enabled
	 */
	public function onEnable() : void{
		$this->reloadConfig();

		$server = $this->getServer();
		$pluginsPath = $server->getPluginPath();
		$pluginManager = $server->getPluginManager();
		foreach(new \DirectoryIterator($pluginsPath) as $fileName){ //plugins 폴더를 탐색
			$pluginDir = "{$pluginsPath}{$fileName}";
			if($fileName === "." || $fileName === ".." || !is_dir($pluginDir)){ //폴더가 아닐 경우 넘어감
				continue;
			}

			$descriptionFile = "{$pluginDir}/plugin.yml";
			if(!file_exists($descriptionFile)){ //plugin.yml 파일이 없을 경우 넘어감
				continue;
			}

			try{
				$description = new plugin\PluginDescription(file_get_contents($descriptionFile));
			}catch(plugin\PluginException $e){ //plugin.yml 파일이 잘못되었을 경우 오류 메세지 출력 후 넘어감
				$this->getLogger()->error($e->getMessage());
				continue;
			}

			$pluginName = $description->getName();
			if(in_array($pluginName, $this->getConfig()->get("autobuild-exceptions", []))){ //자동 빌드 배열에 없을 경우 넘어감
				continue;
			}

			/** @var null|plugin\PluginBase $plugin */
			$plugin = $pluginManager->getPlugin($pluginName);
			if($plugin === $this){ //자기 자신일 경우 넘어감
				continue;
			}

			$pluginVersion = $description->getVersion();
			$pharName = "{$pluginName}_v{$pluginVersion}.phar";
			$buildPath = "{$this->getDataFolder()}{$pharName}";
			$this->buildPhar($description, "{$pluginDir}/", $buildPath);
			if($alreadyLoaded = $plugin !== null){ //플러그인이 이미 로드되었는지 확인
				if(Utils::isPharPath($pluginPath = rtrim(str_replace("\\", "/", $plugin->getFile()), "/"))){ //플러그인 파일이 Phar인지 확인
					$pluginFilePath = ltrim($pluginPath, "phar://");
					//TODO:Phar내 파일 비교 메소드 구현 (현재 sha1의 데이터가 항상 다른 문제점이 존재)
					if(sha1_file($buildPath) !== sha1_file($pluginFilePath)){ //빌드 파일과 다를 경우 존재하는 플러그인 파일을 제거
						try{
							\Phar::unlinkArchive($pluginFilePath);
						}catch(\Exception $e){
							$this->getLogger()->error($e->getMessage());
							unlink($pluginPath);
						}
					}else{ //아닌 경우 빌드를 취소하고 넘어감
						unlink($buildPath);

						$this->getLogger()->info("{$pluginName} 플러그인의 빌드가 취소되었습니다");
						continue;
					}
				}
			}
			rename($buildPath, $pharPath = "{$pluginsPath}{$pharName}");
			$this->getLogger()->info("{$pluginName} 플러그인의 빌드가 완료되었습니다");
			if(!$alreadyLoaded){
				$pluginManager->loadPlugin($pharPath);
			}

			if(!in_array($pluginName, $this->getConfig()->get("remove-exceptions", []))){ //삭제 제외 배열에 없을 경우 플러그인 소스 폴더를 제거
				try{
					Utils::removeDirectory("{$pluginDir}/");
				}catch(\Exception $e){
					$this->getLogger()->error($e->getMessage());
					continue;
				}
			}
		}

		$this->getServer()->enablePlugins(plugin\PluginLoadOrder::STARTUP);
	}

	/**
	 * @param plugin\PluginDescription $description
	 * @param string                   $pharPath
	 * @param string                   $filePath
	 */
	public function buildPhar(plugin\PluginDescription $description, string $filePath, string $pharPath) : void{
		$setting = $this->getConfig()->getAll();
		if(file_exists($pharPath)){
			try{
				\Phar::unlinkArchive($pharPath);
			}catch(\Exception $e){
				unlink($pharPath);
			}
		}
		$phar = new \Phar($pharPath);
		$phar->setSignatureAlgorithm(\Phar::SHA1);
		if(!$setting["skip-metadata"]){
			$phar->setMetadata([
								   "name" => $description->getName(),
								   "version" => $description->getVersion(),
								   "main" => $description->getMain(),
								   "api" => $description->getCompatibleApis(),
								   "depend" => $description->getDepend(),
								   "description" => $description->getDescription(),
								   "authors" => $description->getAuthors(),
								   "website" => $description->getWebsite(),
								   "creationDate" => time()
							   ]);
		}
		if(!$setting["skip-stub"]){
			$phar->setStub('<?php echo "PocketMine-MP plugin ' . "{$description->getName()}_v{$description->getVersion()}\nThis file has been generated using AutoBuildPlugin at " . date("r") . '\n----------------\n";if(extension_loaded("phar")){$phar = new \Phar(__FILE__);foreach($phar->getMetadata() as $key => $value){echo ucfirst($key).": ".(is_array($value) ? implode(", ", $value):$value)."\n";}} __HALT_COMPILER();');
		}else{
			$phar->setStub("<?php __HALT_COMPILER();");
		}

		if(file_exists($buildFolder = "{$this->getDataFolder()}build/")){
			Utils::removeDirectory($buildFolder);
		}
		mkdir($buildFolder);
		foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($filePath)) as $path => $fileInfo){
			$fileName = $fileInfo->getFilename();
			if($fileName !== "." && $fileName !== ".."){
				$inPath = substr($path, strlen($filePath));
				if(!$setting["include-minimal"] || $inPath === "plugin.yml" || strpos($inPath, "src\\") === 0 || strpos($inPath, "resources\\") === 0){
					$newFilePath = "{$buildFolder}{$inPath}";
					$newFileDir = dirname($newFilePath);
					if(!file_exists($newFileDir)){
						mkdir($newFileDir, 0777, true);
					}
					if(substr($path, -4) == ".php"){
						$contents = \file_get_contents($path);
						if($setting["code-optimize"]){
							$contents = Utils::codeOptimize($contents);
						}
						if($setting["rename-variable"]){
							$contents = Utils::renameVariable($contents);
						}
						if($setting["remove-comment"]){
							$contents = Utils::removeComment($contents);
						}
						if($setting["remove-whitespace"]){
							$contents = Utils::removeWhitespace($contents);
						}
						file_put_contents($newFilePath, $contents);
					}else{
						copy($path, $newFilePath);
					}
				}
			}
		}
		$phar->startBuffering();
		$phar->buildFromDirectory($buildFolder);
		if($setting["compress"] && \Phar::canCompress(\Phar::GZ)){
			$phar->compressFiles(\Phar::GZ);
		}
		$phar->stopBuffering();
		Utils::removeDirectory("{$this->getDataFolder()}build/");
	}
}
