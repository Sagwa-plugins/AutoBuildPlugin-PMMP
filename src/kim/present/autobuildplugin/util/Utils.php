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

namespace kim\present\autobuildplugin\util;

class Utils{
	/** Whitespaces left and right from this signs can be ignored */
	private const WHITESPACE_IGNORE_TOKEN = [
		T_CONCAT_EQUAL,
		T_DOUBLE_ARROW,
		T_BOOLEAN_AND,
		T_BOOLEAN_OR,
		T_IS_EQUAL,
		T_IS_NOT_EQUAL,
		T_IS_SMALLER_OR_EQUAL,
		T_IS_GREATER_OR_EQUAL,
		T_INC,
		T_DEC,
		T_PLUS_EQUAL,
		T_MINUS_EQUAL,
		T_MUL_EQUAL,
		T_DIV_EQUAL,
		T_IS_IDENTICAL,
		T_IS_NOT_IDENTICAL,
		T_DOUBLE_COLON,
		T_PAAMAYIM_NEKUDOTAYIM,
		T_OBJECT_OPERATOR,
		T_DOLLAR_OPEN_CURLY_BRACES,
		T_AND_EQUAL,
		T_MOD_EQUAL,
		T_XOR_EQUAL,
		T_OR_EQUAL,
		T_SL,
		T_SR,
		T_SL_EQUAL,
		T_SR_EQUAL
	];

	/**
	 * @url http://php.net/manual/en/function.php-strip-whitespace.php#82437
	 *
	 * @param string $originalCode
	 *
	 * @return string
	 */
	public static function removeWhitespace(string $originalCode) : string{
		$tokens = token_get_all($originalCode);

		$stripedCode = "";
		$c = count($tokens);
		$ignoreWhitespace = false;
		$lastSign = "";
		$openTag = null;
		for($i = 0; $i < $c; $i++){
			$token = $tokens[$i];
			if(is_array($token)){
				list($tokenNumber, $tokenString) = $token; // tokens: number, string, line
				if(in_array($tokenNumber, self::WHITESPACE_IGNORE_TOKEN)){
					$stripedCode .= $tokenString;
					$ignoreWhitespace = true;
				}elseif($tokenNumber == T_INLINE_HTML){
					$stripedCode .= $tokenString;
					$ignoreWhitespace = false;
				}elseif($tokenNumber == T_OPEN_TAG){
					if(strpos($tokenString, " ") || strpos($tokenString, "\n") || strpos($tokenString, "\t") || strpos($tokenString, "\r")){
						$tokenString = rtrim($tokenString);
					}
					$tokenString .= " ";
					$stripedCode .= $tokenString;
					$openTag = T_OPEN_TAG;
					$ignoreWhitespace = true;
				}elseif($tokenNumber == T_OPEN_TAG_WITH_ECHO){
					$stripedCode .= $tokenString;
					$openTag = T_OPEN_TAG_WITH_ECHO;
					$ignoreWhitespace = true;
				}elseif($tokenNumber == T_CLOSE_TAG){
					if($openTag == T_OPEN_TAG_WITH_ECHO){
						$stripedCode = rtrim($stripedCode, "; ");
					}else{
						$tokenString = " " . $tokenString;
					}
					$stripedCode .= $tokenString;
					$openTag = null;
					$ignoreWhitespace = false;
				}elseif($tokenNumber == T_CONSTANT_ENCAPSED_STRING || $tokenNumber == T_ENCAPSED_AND_WHITESPACE){
					if($tokenString[0] == "\""){
						$tokenString = addcslashes($tokenString, "\n\t\r");
					}
					$stripedCode .= $tokenString;
					$ignoreWhitespace = true;
				}elseif($tokenNumber == T_WHITESPACE){
					$nt = @$tokens[$i + 1];
					if(!$ignoreWhitespace && (!is_string($nt) || $nt == "\$") && !in_array($nt[0], self::WHITESPACE_IGNORE_TOKEN)){
						$stripedCode .= " ";
					}
					$ignoreWhitespace = false;
				}elseif($tokenNumber == T_START_HEREDOC){
					$stripedCode .= "<<<S\n";
					$ignoreWhitespace = false;
				}elseif($tokenNumber == T_END_HEREDOC){
					$stripedCode .= "S;";
					$ignoreWhitespace = true;
					for($j = $i + 1; $j < $c; $j++){
						if(is_string($tokens[$j]) && $tokens[$j] == ";"){
							$i = $j;
							break;
						}else{
							if($tokens[$j][0] == T_CLOSE_TAG){
								break;
							}
						}
					}
				}else{
					$stripedCode .= $tokenString;
					$ignoreWhitespace = false;
				}
				$lastSign = "";
			}else{
				if(($token != ";" && $token != ":") || $lastSign != $token){
					$stripedCode .= $token;
					$lastSign = $token;
				}
				$ignoreWhitespace = true;
			}
		}
		return $stripedCode;
	}


	private const REMOVE_IGNORE_ANNOTATION_MAP = [
		"@priority" => "/^[\t ]*\* @priority[\t ]{1,}([a-zA-Z]{1,})/m",
		"@notHandler" => "/^[\t ]*\* @notHandler(|[\t ]{1,}([a-zA-Z]{1,}))/m",
		"@softDepend" => "/^[\t ]*\* @softDepend[\t ]{1,}([a-zA-Z]{1,})/m",
		"@handleCancelled" => "/^[\t ]*\* @handleCancelled(|[\t ]{1,}([a-zA-Z]{1,}))/m"
	];

	/**
	 * @param string $originalCode
	 *
	 * @return string
	 */
	public static function removeComment(string $originalCode) : string{
		$tokens = token_get_all($originalCode);
		$stripedCode = "";
		for($i = 0, $count = count($tokens); $i < $count; $i++){
			if(is_array($tokens[$i])){
				if($tokens[$i][0] === T_COMMENT){
					continue;
				}elseif($tokens[$i][0] === T_DOC_COMMENT){
					$annotations = [];
					foreach(self::REMOVE_IGNORE_ANNOTATION_MAP as $annotation => $regex){
						if(preg_match($regex, $tokens[$i][1], $matches) > 0){
							$annotations[] = "{$annotation} {$matches[1]}";
						}
					}
					$tokens[$i][1] = "";
					if(!empty($annotations)){
						$tokens[$i][1] .= "/** " . PHP_EOL;
						foreach($annotations as $value){
							$tokens[$i][1] .= "* $value" . PHP_EOL;
						}
						$tokens[$i][1] .= "*/";
					}
				}
				$stripedCode .= $tokens[$i][1];
			}else{
				$stripedCode .= $tokens[$i];
			}
		}
		return $stripedCode;
	}


	private const RENAME_IGNORE_BEFORE = [
		"protected",
		"private",
		"public",
		"static",
		"final",
		"::"
	];
	private static $firstChars = null;
	private static $otherChars = null;

	/**
	 * @param string $originalCode
	 *
	 * @return string
	 */
	public static function renameVariable(string $originalCode) : string{
		if(self::$firstChars === null){
			self::$firstChars = $firstChars = array_merge(range("a", "z"), range("A", "Z"));
			array_unshift(self::$firstChars, "_");
		}
		if(self::$otherChars === null){
			self::$otherChars = array_merge(range("0", "9"), self::$firstChars);
			array_unshift(self::$otherChars, "_");
		}
		$firstCharCount = count(self::$firstChars);
		$variables = ["\$this" => "\$this"];
		$variableCount = 0;
		$tokens = token_get_all($originalCode);
		$stripedCode = "";
		for($i = 0, $count = count($tokens); $i < $count; $i++){
			if(is_array($tokens[$i])){
				$beforeIndex = $i - 1;
				/** @var null|string $before */
				$before = null;
				while(isset($tokens[$beforeIndex])){
					$token = $tokens[$beforeIndex--];
					if(is_array($token)){
						if($token[0] === T_WHITESPACE or $token[0] === T_COMMENT or $token[0] === T_DOC_COMMENT){
							continue;
						}
						$before = $token[1];
						break;
					}else{
						$before = $token;
						break;
					}
				}
				if($tokens[$i][0] === T_VARIABLE && !Utils::in_arrayi($before, self::RENAME_IGNORE_BEFORE)){
					if(!isset($variables[$tokens[$i][1]])){
						$variableName = "\$" . self::$firstChars[$variableCount % $firstCharCount];
						if($variableCount){
							if(($sub = floor($variableCount / $firstCharCount) - 1) > -1){
								$variableName .= self::$otherChars[$sub];
							}
						}
						++$variableCount;
						$variables[$tokens[$i][1]] = $variableName;
					}
					$tokens[$i][1] = $variables[$tokens[$i][1]];
				}
				$stripedCode .= $tokens[$i][1];
			}else{
				$stripedCode .= $tokens[$i];
			}
		}
		return $stripedCode;
	}


	private const OPTIMIZE_IGNORE_BEFORE = [
		"\\",
		"::",
		"->",
		"function"
	];

	/**
	 * @param string $originalCode
	 *
	 * @return string
	 */
	public static function codeOptimize(string $originalCode) : string{
		$tokens = token_get_all($originalCode);
		$stripedCode = "";
		for($i = 0, $count = count($tokens); $i < $count; $i++){
			if(is_array($tokens[$i])){
				if($tokens[$i][0] === T_STRING){
					$beforeIndex = $i - 1;
					/** @var null|string $before */
					$before = null;
					while(isset($tokens[$beforeIndex])){
						$token = $tokens[$beforeIndex--];
						if(is_array($token)){
							if($token[0] === T_WHITESPACE or $token[0] === T_COMMENT or $token[0] === T_DOC_COMMENT){
								continue;
							}
							$before = $token[1];
							break;
						}else{
							$before = $token;
							break;
						}
					}
					if($before === null || !Utils::in_arrayi($before, self::OPTIMIZE_IGNORE_BEFORE)){
						if(defined("\\" . $tokens[$i][1])){
							$tokens[$i][1] = "\\" . $tokens[$i][1];
						}elseif(function_exists("\\" . $tokens[$i][1]) && isset($tokens[$i + 1]) && $tokens[$i + 1] === "("){
							$tokens[$i][1] = "\\" . $tokens[$i][1];
						}
					}
				}elseif($tokens[$i][0] === T_LOGICAL_OR){
					$tokens[$i][1] = "||";
				}elseif($tokens[$i][0] === T_LOGICAL_AND){
					$tokens[$i][1] = "&&";
				}
				$stripedCode .= $tokens[$i][1];
			}else{
				$stripedCode .= $tokens[$i];
			}
		}
		return $stripedCode;
	}


	/**
	 * @param string $str
	 * @param array  $strs
	 *
	 * @return bool
	 */
	public static function in_arrayi(string $str, array $strs) : bool{
		foreach($strs as $key => $value){
			if(strcasecmp($str, $value) === 0){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $directory
	 *
	 * @return bool
	 */
	public static function removeDirectory(string $directory) : bool{
		$files = array_diff(scandir($directory), [
			".",
			".."
		]);
		foreach($files as $file){
			if(is_dir($fileName = "{$directory}/{$file}")){
				Utils::removeDirectory($fileName);
			}else{
				unlink($fileName);
			}
		}
		return rmdir($directory);
	}

	/**
	 * @param string $path
	 *
	 * @return bool, whether the $path startswith "phar://"
	 */
	public static function isPharPath(string $path) : bool{
		return strpos($path, "phar://") === 0;
	}
}
