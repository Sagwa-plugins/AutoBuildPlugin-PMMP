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
		Utils::removeDirectory($buildFolder = "{$this->getDataFolder()}build/");
	}
}
