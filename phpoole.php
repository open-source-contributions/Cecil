#!/usr/bin/env php
<?php
/**
 * PHPoole is a light and easy static website generator written in PHP.
 * @see http://narno.org/PHPoole/
 *
 * @author Arnaud Ligny <arnaud@ligny.org>
 * @license The MIT License (MIT)
 *
 * Copyright (c) 2013 Arnaud Ligny
 */

//error_reporting(0);

use Zend\Console\Console;
use Zend\Console\Getopt;
use Zend\Console\ColorInterface as Color;
use Zend\Console\Exception\RuntimeException as ConsoleException;
use Michelf\MarkdownExtra;

// Composer autoloading
if (file_exists('vendor/autoload.php')) {
    $loader = include 'vendor/autoload.php';
}
else {
    echo 'Run the following commands:' . PHP_EOL;
    if (!file_exists('composer.json')) {
        echo 'curl https://raw.github.com/Narno/PHPoole/master/composer.json > composer.json' . PHP_EOL;
    }
    if (!file_exists('composer.phar')) {
        echo 'curl -s http://getcomposer.org/installer | php' . PHP_EOL;
    }  
    echo 'php composer.phar install' . PHP_EOL;
    exit(2);
}

try {
    $console = Console::getInstance();
    $phpooleConsole = new PHPooleConsole($console);
} catch (ConsoleException $e) {
    // Could not get console adapter - most likely we are not running inside a console window.
}

define('DS', DIRECTORY_SEPARATOR);
define('PHPOOLE_DIRNAME', '_phpoole');
$websitePath = getcwd();

// Defines rules
$rules = array(
    'help|h'     => 'Get PHPoole usage message',
    'init|i-s'   => 'Build a new PHPoole website (with <bootstrap>)',
    'generate|g' => 'Generate static files',
    'serve|s'    => 'Start built-in web server',
    'deploy|d'   => 'Deploy static files',
    'list|l=s'   => 'Lists <pages> or <posts>',
);

// Get and parse console options
try {
    $opts = new Getopt($rules);
    $opts->parse();
} catch (ConsoleException $e) {
    echo $e->getUsageMessage();
    exit(2);
}

// help option
if ($opts->getOption('help') || count($opts->getOptions()) == 0) {
    echo $opts->getUsageMessage();
    exit(0);
}

// Get provided directory if exist
$remainingArgs = $opts->getRemainingArgs();
if (isset($remainingArgs[0])) {
    if (!is_dir($remainingArgs[0])) {
        $phpooleConsole->wlError('Invalid directory provided');
        exit(2);
    }
    $websitePath = str_replace(DS, '/', realpath($remainingArgs[0]));
}

// Instanciate PHPoole API
try {
    $phpoole = new PHPoole($websitePath);
}
catch (Exception $e) {
    $phpooleConsole->wlError($e->getMessage());
    exit(2);
}

// init option
if ($opts->getOption('init')) {
    $layoutType = '';
    $phpooleConsole->wlInfo('Initializing new website');
    if ((string)$opts->init == 'bootstrap') {
        $layoutType = 'bootstrap';
    }
    try {
        $messages = $phpoole->init($layoutType);
        foreach ($messages as $message) {
            $phpooleConsole->wlDone($message);
        }
    }  
    catch (Exception $e) {
        $phpooleConsole->wlError($e->getMessage());
    }
}

// generate option
if ($opts->getOption('generate')) {
    $config = array();
    $phpooleConsole->wlInfo('Generate website');
    if (isset($opts->serve)) {
        $config['site']['base_url'] = 'http://localhost:8000';
        $phpooleConsole->wlInfo('Youd should re-generate before deploy');
    }
    try {
        $messages = $phpoole->generate($config);
        foreach ($messages as $message) {
            $phpooleConsole->wlDone($message);
        }
    }  
    catch (Exception $e) {
        $phpooleConsole->wlError($e->getMessage());
    }
}

// serve option
if ($opts->getOption('serve')) {
    if (version_compare(PHP_VERSION, '5.4.0', '<')) {
        $phpooleConsole->wlError('PHP 5.4+ required to run built-in server (your version: ' . PHP_VERSION . ')');
        exit(2);
    }
    if (!is_file(sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME))) {
        $phpooleConsole->wlError('Router not found');
        exit(2);
    }
    $phpooleConsole->wlInfo(sprintf("Start server http://%s:%d", 'localhost', '8000'));
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = sprintf(
            //'START /B php -S %s:%d -t %s %s > nul',
            'START php -S %s:%d -t %s %s > nul',
            'localhost',
            '8000',
            $websitePath,
            sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME)
        );
    }
    else {
        echo 'Ctrl-C to stop it.' . PHP_EOL;
        $command = sprintf(
            //'php -S %s:%d -t %s %s >/dev/null 2>&1 & echo $!',
            'php -S %s:%d -t %s %s >/dev/null',
            'localhost',
            '8000',
            $websitePath,
            sprintf('%s/%s/router.php', $websitePath, PHPOOLE_DIRNAME)
        );
    }
    exec($command);
}

// deploy option
if ($opts->getOption('deploy')) {
    $phpooleConsole->wlInfo('Deploy website on GitHub');
    try {
        $phpoole->deploy();
    }  
    catch (Exception $e) {
        $console->writeLine(sprintf("[KO] %s", $e->getMessage()), Color::WHITE, Color::RED);
        $phpooleConsole->wlError($e->getMessage());
    }
}

// list option
if ($opts->getOption('list')) {
    if (isset($opts->list) && $opts->list == 'pages') {
        $phpooleConsole->wlInfo('List pages');
        if (!is_dir($websitePath . '/' . PHPOOLE_DIRNAME . '/content/pages')) {
            $phpooleConsole->wlError('Invalid content/pages directory');
            echo $opts->getUsageMessage();
            exit(2);
        }
        $contentIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($websitePath . '/' . PHPOOLE_DIRNAME . '/content/pages'),
            RecursiveIteratorIterator::CHILD_FIRST
        );    
        foreach($contentIterator as $file) {
            if ($file->isFile()) {
                printf("- %s%s\n", ($contentIterator->getSubPath() != '' ? $contentIterator->getSubPath() . '/' : ''), $file->getFilename());
            }
        }
    }
    else if (isset($opts->list) && $opts->list == 'posts') {
        $phpooleConsole->wlInfo('List posts');
        // @todo todo! :-)
    }
    else {
        echo $opts->getUsageMessage();
        exit(2);
    }
}


/**
 * PHPoole API
 */
class PHPoole
{
    const PHPOOLE_DIRNAME = '_phpoole';
    const CONFIG_FILENAME = 'config.ini';
    const LAYOUTS_DIRNAME = 'layouts';
    const ASSETS_DIRNAME  = 'assets';
    const CONTENT_DIRNAME = 'content';
    const CONTENT_PAGES_DIRNAME = 'pages';
    const CONTENT_POSTS_DIRNAME = 'posts';

    protected $websitePath;
    protected $websiteFileInfo;

    public function __construct($websitePath)
    {
        $splFileInfo = new SplFileInfo($websitePath);
        if (!$splFileInfo->isDir()) {
            throw new Exception('Invalid directory provided');
        }
        else {
            $this->websiteFileInfo = $splFileInfo;
            $this->websitePath = $splFileInfo->getRealPath();
        }
    }

    public function getWebsiteFileInfo()
    {
        return $this->websiteFileInfo;
    }

    public function getWebsitePath()
    {
        return $this->websitePath;
    }

    public function init($type='default', $force=false) {
        if (file_exists($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONFIG_FILENAME)) {
            if ($force === true) {
                RecursiveRmdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME);
            }
            else {
                throw new Exception('The website is already initialized');
            }
        }
        if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME)) {
            throw new Exception('Cannot create root PHPoole directory');
        }
        $messages = array(
            self::PHPOOLE_DIRNAME . ' directory created',
            $this->createConfigFile(),
            $this->createLayoutsDir(),
            $this->createLayoutDefaultFile($type),
            $this->createAssetsDir(),
            $this->createAssetDefaultFiles($type),
            $this->createContentDir(),
            $this->createContentDefaultFile(),
            $this->createRouterFile(),
        );
        return $messages;
    }

    private function createConfigFile()
    {
        $content = <<<'EOT'
[site]
name        = "PHPoole"
baseline    = "Light and easy static website generator!"
description = "PHPoole is a simple static website/weblog generator written in PHP. It parses your content written with Markdown, merge it with layouts and generates static HTML files."
base_url    = "http://localhost:8000"
language    = "en"
[author]
name  = "Arnaud Ligny"
email = "arnaud+phpoole@ligny.org"
home  = "http://narno.org"
[deploy]
repository = "https://github.com/Narno/PHPoole.git"
branch     = "gh-pages"
EOT;

        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONFIG_FILENAME, $content)) {
            throw new Exception('Cannot create the config file');
        }
        return 'Config file created';
    }

    private function createLayoutsDir()
    {
        if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::LAYOUTS_DIRNAME)) {
            throw new Exception('Cannot create the layouts directory');
        }
        return 'Layouts directory created';
    }

    private function createLayoutDefaultFile($type='')
    {
        if ($type == 'bootstrap') {
            $content = <<<'EOT'
<!DOCTYPE html>
<html lang="{{ site.language }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{ site.description }}">
    <meta name="author" content="{{ author.name }}">
    <title>{{ site.name }} - {{ title|title }}</title>
    <link href="{{ site.base_url }}/assets/css/bootstrap.min.css" rel="stylesheet">
    <style type="text/css">
      html, body {height: 100%;}
      #wrap {min-height: 100%;height: auto !important;height: 100%;margin: 0 auto -60px;padding: 0 0 60px;}
      #footer {height: 60px;background-color: #f5f5f5;}
      #wrap > .container {padding: 60px 15px 0;}
      .container .credit {margin: 20px 0;}
      #footer > .container {padding-left: 15px;padding-right: 15px;}
      code {font-size: 80%;}
    </style>
    <!-- HTML5 shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!--[if lt IE 9]>
      <script src="http://getbootstrap.com/assets/js/html5shiv.js"></script>
      <script src="http://getbootstrap.com/assets/js/respond.min.js"></script>
    <![endif]-->
  <body>
    {% if source.repository %}
    <a href="{{ source.repository }}"><img style="position: absolute; top: 0; right: 0; border: 0; z-index: 9999;" src="https://s3.amazonaws.com/github/ribbons/forkme_right_gray_6d6d6d.png" alt="Fork me on GitHub"></a>
    {% endif %}
    <div id="wrap">
      <div class="navbar navbar-default navbar-fixed-top">
        <div class="container">
          <div class="navbar-header">
            <button type="button" class="navbar-toggle" data-toggle="collapse" data-target=".navbar-collapse">
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="{{ site.base_url }}">{{ site.name}}</a>
          </div>
          <div class="collapse navbar-collapse">
            <ul class="nav navbar-nav">
              {% for item in nav %}
              <li {% if item.path == path %}class="active"{% endif %}><a href="{{ site.base_url }}{% if item.path != '' %}/{{ item.path }}{% endif %}">{{ item.title|e }}</a></li>
              {% endfor %}
            </ul>
          </div><!--/.nav-collapse -->
        </div>
      </div>
      <!-- Begin page content -->
      <div class="container">
        <div class="page-header">
          <h1>{{ site.name}}</h1>
          <p class="lead"><em>{{ site.baseline }}</em></p>
        </div>
        {{ content }}
      </div>
    </div>
    <div id="footer">
      <div class="container">
        <p class="text-muted credit">&copy; <a href="{{ author.home }}">{{ author.name }}</a> {{ 'now'|date('Y') }} - Powered by <a href="http://narno.org/PHPoole">PHPoole</a></p>
      </div>
    </div>
    <script src="{{ site.base_url }}/assets/js/jquery.min.js"></script>
    <script src="{{ site.base_url }}/assets/js/bootstrap.min.js"></script>
  </body>
</html>
EOT;
        }
        else {
            $content = <<<'EOT'
<!DOCTYPE html>
<!--[if IE 8]><html class="no-js lt-ie9" lang="{{ site.language }}"><![endif]-->
<!--[if gt IE 8]><!--><html class="no-js" lang="{{ site.language }}"><!--<![endif]-->
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width">
  <meta name="description" content="{{ site.description }}">
  <title>{{ site.name}} - {{ title }}</title>
  <style type="text/css">
    body { font: bold 24px Helvetica, Arial; padding: 15px 20px; color: #ddd; background: #333;}
    a:link {text-decoration: none; color: #fff;}
    a:visited {text-decoration: none; color: #fff;}
    a:active {text-decoration: none; color: #fff;}
    a:hover {text-decoration: underline; color: #fff;}
  </style>
</head>
<body>
  <a href="{{ site.base_url}}"><strong>{{ site.name }}</strong></a><br />
  <em>{{ site.baseline }}</em>
  <hr />
  <p>{{ content }}</p>
  <hr />
  <p>Powered by <a href="http://narno.org/PHPoole">PHPoole</a>, coded by <a href="{{ author.home }}">{{ author.name }}</a></p>
</body>
</html>
EOT;
        }
        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::LAYOUTS_DIRNAME . '/default.html', $content)) {
            throw new Exception('Cannot create the default layout file');
        }
        return 'Default layout file created';
    }

    private function createAssetsDir()
    {
        $subDirList = array(
            self::ASSETS_DIRNAME,
            self::ASSETS_DIRNAME . '/css',
            self::ASSETS_DIRNAME . '/img',
            self::ASSETS_DIRNAME . '/js',
        );
        foreach ($subDirList as $subDir) {
            if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . $subDir)) {
                throw new Exception('Cannot create the assets directory');
            }
        }
        return 'Assets directory created';
    }

    private function createAssetDefaultFiles($type='')
    {
        if ($type == 'bootstrap') {
            echo 'Downloading Twitter Bootstrap assets files and jQuery script...' . PHP_EOL;
            $curlCommandes = array(
                sprintf('curl %s > %s/css/bootstrap.min.css 2>&1', 'https://raw.github.com/twbs/bootstrap/v3.0.0/dist/css/bootstrap.min.css', $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::ASSETS_DIRNAME),
                sprintf('curl %s > %s/js/bootstrap.min.js 2>&1', 'https://raw.github.com/twbs/bootstrap/v3.0.0/dist/js/bootstrap.min.js', $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::ASSETS_DIRNAME),
                sprintf('curl %s > %s/js/jquery.min.js 2>&1', 'http://code.jquery.com/jquery-2.0.3.min.js', $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::ASSETS_DIRNAME),
            );
            foreach ($curlCommandes as $cmd) {
                exec($cmd, $output, $return_var);
            }
            if ($return_var != 0) {
                switch ($return_var) {
                    case 7:
                        $exceptionMessage = 'Cannot connect to host';
                        break;
                    case 1:
                        $exceptionMessage = 'Be sure libcurl is installed';
                        break;
                    default:
                        $exceptionMessage = sprintf("Cannot download Bootstrap files, cURL error %s", $return_var);
                        break;
                }
                throw new Exception($exceptionMessage);
            }
            return 'Default assets files downloaded';
        }
        else {
            return 'Assets files not needed';
        }
    }

    private function createContentDir()
    {
        $subDirList = array(
            self::CONTENT_DIRNAME,
            self::CONTENT_DIRNAME . '/' . self::CONTENT_PAGES_DIRNAME,
            self::CONTENT_DIRNAME . '/' . self::CONTENT_POSTS_DIRNAME,
        );
        foreach ($subDirList as $subDir) {
            if (!@mkdir($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . $subDir)) {
                throw new Exception('Cannot create the content directory');
            }
        }
        return 'Content directory created';
    }

    private function createContentDefaultFile()
    {
        $content = <<<'EOT'
<!--
title = Home
layout = default
menu = nav
-->
Welcome!
========

PHPoole is a simple static website/weblog generator written in PHP.
It parses your content written with Markdown, merge it with layouts and generates static HTML files.

PHPoole = [PHP](http://www.php.net) + [Poole](http://en.wikipedia.org/wiki/Strange_Case_of_Dr_Jekyll_and_Mr_Hyde#Mr._Poole)

Go to the [dedicated website](http://narno.org/PHPoole) for more details.
EOT;
        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONTENT_DIRNAME . '/' . self::CONTENT_PAGES_DIRNAME . '/index.md', $content)) {
            throw new Exception('Cannot create the default content file');
        }
        return 'Default content file created';
    }

    private function createRouterFile()
    {
        $content = <<<'EOT'
<?php
date_default_timezone_set("UTC");
define("DIRECTORY_INDEX", "index.html");
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$ext = pathinfo($path, PATHINFO_EXTENSION);
if (empty($ext)) {
    $path = rtrim($path, "/") . "/" . DIRECTORY_INDEX;
}
if (file_exists($_SERVER["DOCUMENT_ROOT"] . $path)) {
    return false;
}
http_response_code(404);
echo "404, page not found";
EOT;
        if (!@file_put_contents($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/router.php', $content)) {
            throw new Exception('Cannot create the router file');
        }
        return 'Router file created';
    }

    private function createReadmeFile()
    {
        $content = <<<'EOT'
Powered by [PHPoole](http://narno.org/PHPoole/).
EOT;
        
        if (is_file($this->getWebsitePath() . '/README.md')) {
            if (!@unlink($this->getWebsitePath() . '/README.md')) {
                throw new Exception('Cannot create the README file');
            }
        }
        if (!@file_put_contents($this->getWebsitePath() . '/README.md', $content)) {
            throw new Exception('Cannot create the README file');
        }
        return 'README file created';
    }

    public function getConfig()
    {
        $configFilePath = $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONFIG_FILENAME;
        if (!file_exists($configFilePath)) {
            throw new Exception('Cannot get config file');
        }
        return parse_ini_file($configFilePath, true);
    }

    public function parseContent($content, $filename, $config)
    {
        $config = $this->getConfig();
        $parser = new MarkdownExtra;
        $parser->code_attr_on_pre = true;
        $parser->predef_urls = array('base_url' => $config['site']['base_url']);
        preg_match('/^<!--(.+)-->(.+)/s', $content, $matches);
        if (!$matches) {
            //throw new Exception(sprintf("Could not parse front matter in %s\n", $filename));
            return array('content' => $contentHtml = $parser->transform($content));
        }
        list($matchesAll, $rawInfo, $rawContent) = $matches;
        $info = parse_ini_string($rawInfo);
        $contentHtml = $parser->transform($rawContent);
        return array_merge(
            $info,
            array('content' => $contentHtml)
        );
    }

    public function generate($configToMerge=array())
    {
        $pages = array();
        $menu['nav'] = array();
        $config = $this->getConfig();
        if (!empty($configToMerge)) {
            $config = array_replace_recursive($config, $configToMerge);
        }
        $twigLoader = new Twig_Loader_Filesystem($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::LAYOUTS_DIRNAME);
        $twig = new Twig_Environment($twigLoader, array(
            'autoescape' => false,
            'debug'      => true
        ));
        $twig->addExtension(new Twig_Extension_Debug());
        $pagesPath = $this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::CONTENT_DIRNAME . '/' . self::CONTENT_PAGES_DIRNAME;
        $markdownIterator = new MarkdownFileFilter($pagesPath);
        foreach ($markdownIterator as $filePage) {
            if (false === ($content = @file_get_contents($filePage->getPathname()))) {
                throw new Exception(sprintf('Cannot get content of %s/%s', $markdownIterator->getSubPath(), $filePage->getBasename()));
            }
            $page = $this->parseContent($content, $filePage->getFilename(), $config);
            $pageIndex = ($markdownIterator->getSubPath() ? $markdownIterator->getSubPath() : 'home');
            $pages[$pageIndex]['layout'] = (
                isset($page['layout'])
                    && is_file($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/layouts' . '/' . $page['layout'] . '.html')
                ? $page['layout'] . '.html'
                : 'default.html'
            );
            $pages[$pageIndex]['title'] = (
                isset($page['title'])
                    && !empty($page['title'])
                ? $page['title']
                : ucfirst($filePage->getBasename('.md'))
            );
            $pages[$pageIndex]['path'] = $markdownIterator->getSubPath();
            $pages[$pageIndex]['content'] = $page['content'];
            $pages[$pageIndex]['basename'] = $filePage->getBasename('.md') . '.html';
            if (isset($page['menu'])) {
                $menu[$page['menu']][] = (
                    !empty($page['menu'])
                    ? array(
                        'title' => $page['title'],
                        'path'  => $markdownIterator->getSubPath()
                    )
                    : ''
                );
            }
        }
        foreach ($pages as $key => $page) {
            $rendered = $twig->render($page['layout'], array(
                'site'    => $config['site'],
                'author'  => $config['author'],
                'source'  => $config['deploy'],
                'title'   => $page['title'],
                'path'    => $page['path'],
                'content' => $page['content'],
                'nav'     => $menu['nav'],
            ));
            if (!is_dir($this->getWebsitePath() . '/' . $page['path'])) {
                if (!@mkdir($this->getWebsitePath() . '/' . $page['path'], 0777, true)) {
                    throw new Exception(sprintf('Cannot create %s', $this->getWebsitePath() . '/' . $page['path']));
                }
            }
            if (is_file($this->getWebsitePath() . '/' . ($page['path'] != '' ? $page['path'] . '/' : '') . $page['basename'])) {
                if (!@unlink($this->getWebsitePath() . '/' . ($page['path'] != '' ? $page['path'] . '/' : '') . $page['basename'])) {
                    throw new Exception(sprintf('Cannot delete %s%s', ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']));
                }
                $messages[] = 'Delete ' . ($page['path'] != '' ? $page['path'] . '/' : '') . $page['basename'];
            }
            if (!@file_put_contents(sprintf('%s%s', $this->getWebsitePath() . '/' . ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']), $rendered)) {
                throw new Exception(sprintf('Cannot write %s%s', ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']));
            }
            $messages[] = sprintf("Write %s%s", ($page['path'] != '' ? $page['path'] . '/' : ''), $page['basename']);
            
        }
        if (is_dir($this->getWebsitePath() . '/' . self::LAYOUTS_DIRNAME)) {
            RecursiveRmdir($this->getWebsitePath() . '/' . self::LAYOUTS_DIRNAME);
        }
        RecursiveCopy($this->getWebsitePath() . '/' . self::PHPOOLE_DIRNAME . '/' . self::ASSETS_DIRNAME, $this->getWebsitePath() . '/' . self::ASSETS_DIRNAME);
        $messages[] = 'Copy assets directory (and sub)';
        $messages[] = $this->createReadmeFile();
        return $messages;
    }

    public function deploy()
    {
        $config = $this->getConfig();
        if (!isset($config['deploy']['repository']) && !isset($config['deploy']['branch'])) {
            throw new Exception('Cannot found the repository name in the config file');
        }
        else {
            $repoUrl = $config['deploy']['repository'];
            $repoBranch = $config['deploy']['branch'];
        }
        $deployDir = $this->getWebsitePath() . '/../.' . basename($this->getWebsitePath());
        if (is_dir($deployDir)) {
            //echo 'Deploying files to GitHub...' . PHP_EOL;
            $deployIterator = new FilesystemIterator($deployDir);
            foreach ($deployIterator as $deployFile) {
                if ($deployFile->isFile()) {
                    @unlink($deployFile->getPathname());
                }
                if ($deployFile->isDir() && $deployFile->getFilename() != '.git') {
                    RecursiveRmDir($deployFile->getPathname());
                }
            }
            RecursiveCopy($this->getWebsitePath(), $deployDir);
            $updateRepoCmd = array(
                'add -A',
                'commit -m "Update ' . $repoBranch . ' via PHPoole"',
                'push github ' . $repoBranch . ' --force'
            );
            $this->runGitCmd($deployDir, $updateRepoCmd);
        }
        else {
            //echo 'Setting up GitHub deployment...' . PHP_EOL;
            @mkdir($deployDir);
            RecursiveCopy($this->getWebsitePath(), $deployDir);
            $initRepoCmd = array(
                'init',
                'add -A',
                'commit -m "Create ' . $repoBranch . ' via PHPoole"',
                'branch -M ' . $repoBranch . '',
                'remote add github ' . $repoUrl,
                'push github ' . $repoBranch . ' --force'
            );
            $this->runGitCmd($deployDir, $initRepoCmd);
        }
    }

    public function runGitCmd($wd, $commands)
    {
        $cwd = getcwd();
        chdir($wd);
        exec('git config core.autocrlf false');
        foreach ($commands as $cmd) {
            //printf("> git %s\n", $cmd);
            exec(sprintf('git %s', $cmd));
        }
        chdir($cwd);
    }
}

class PHPooleConsole
{
    protected $console;

    public function __construct($console)
    {
        if (!($console instanceof Zend\Console\Adapter\AdapterInterface)) {
            throw new Exception("Error");
        }
        $this->console = $console;
    }

    public function wlInfo($text)
    {
        echo '[' , $this->console->write('INFO', Color::YELLOW) , ']' . "\t";
        $this->console->writeLine($text);
    }
    public function wlDone($text)
    {
        echo '[' , $this->console->write('DONE', Color::GREEN) , ']' . "\t";
        $this->console->writeLine($text);
    }
    public function wlError($text)
    {
        echo '[' , $this->console->write('ERROR', Color::RED) , ']' . "\t";
        $this->console->writeLine($text);
    }
}

/**
 * Utils
 */

/**
 * Recursively remove a directory
 *
 * @param string $dirname
 * @param boolean $followSymlinks
 * @return boolean
 */
function RecursiveRmdir($dirname, $followSymlinks=false) {
    if (is_dir($dirname) && !is_link($dirname)) {
        if (!is_writable($dirname)) {
            throw new Exception(sprintf('%s is not writable!', $dirname));
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirname),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        while ($iterator->valid()) {
            if (!$iterator->isDot()) {
                if (!$iterator->isWritable()) {
                    throw new Exception(sprintf(
                        '%s is not writable!',
                        $iterator->getPathName()
                    ));
                }
                if ($iterator->isLink() && $followLinks === false) {
                    $iterator->next();
                }
                if ($iterator->isFile()) {
                    @unlink($iterator->getPathName());
                }
                elseif ($iterator->isDir()) {
                    @rmdir($iterator->getPathName());
                }
            }
            $iterator->next();
        }
        unset($iterator);
 
        return @rmdir($dirname);
    }
    else {
        throw new Exception(sprintf('%s does not exist!', $dirname));
    }
}

/**
 * Copy a dir, and all its content from source to dest
 */
function RecursiveCopy($source, $dest) {
    if (!is_dir($dest)) {
        @mkdir($dest);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @mkdir($dest . DS . $iterator->getSubPathName());
        }
        else {
            @copy($item, $dest . DS . $iterator->getSubPathName());
        }
    }
}

/**
 * Markdown file iterator
 */
class MarkdownFileFilter extends FilterIterator
{
    public function __construct($dirOrIterator = '.')
    {
        if (is_string($dirOrIterator)) {
            if (!is_dir($dirOrIterator)) {
                throw new InvalidArgumentException('Expected a valid directory name');
            }
            $dirOrIterator = new RecursiveDirectoryIterator($dirOrIterator, RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
        }
        elseif (!$dirOrIterator instanceof DirectoryIterator) {
            throw new InvalidArgumentException('Expected a DirectoryIterator');
        }
        if ($dirOrIterator instanceof RecursiveIterator) {
            $dirOrIterator = new RecursiveIteratorIterator($dirOrIterator);
        }
        parent::__construct($dirOrIterator);
    }

    public function accept()
    {
        $file = $this->getInnerIterator()->current();
        if (!$file instanceof SplFileInfo) {
            return false;
        }
        if (!$file->isFile()) {
            return false;
        }
        if ($file->getExtension() == 'md') {
            return true;
        }
    }
}