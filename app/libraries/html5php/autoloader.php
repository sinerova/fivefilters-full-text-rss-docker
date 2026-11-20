<?php
// Autoloader for the bundled masterminds/html5 (see VERSION.txt).
// Not part of upstream: upstream uses Composer PSR-4 with src/ as the root; here src/ is
// copied into this directory, so "Masterminds\HTML5\Parser\Foo" maps to HTML5/Parser/Foo.php.
spl_autoload_register(array(new HTML5PHP_Autoloader(), 'autoload'));

class HTML5PHP_Autoloader
{
	private $path;

	public function __construct()
	{
		$this->path = dirname(__FILE__);
	}

	public function autoload($class)
	{
		if (strpos($class, 'Masterminds\HTML5') !== 0)
		{
			return;
		}
		$class = substr($class, strlen('Masterminds\\'));
		$filename = $this->path . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
		if (is_file($filename))
		{
			include $filename;
		}
	}
}
