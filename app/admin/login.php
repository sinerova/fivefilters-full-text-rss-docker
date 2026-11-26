<?php
ini_set("display_errors", 0);
ini_set("log_errors", 1);
header("X-Robots-Tag: noindex, nofollow", true);
require_once(dirname(dirname(__FILE__)).'/config.php');
require_once(dirname(__FILE__).'/session_csrf.php');
ftr_start_session($options);
if (!isset($options->admin_credentials) || $options->admin_credentials['username'] == '' || $options->admin_credentials['password'] == '') {
	die('<h2>Admin privileges required</h2><p>This page requires admin privileges but Full-Text RSS has not been configured with admin credentials.</p><p>If you are the administrator, please edit your <tt>custom_config.php</tt> file and enter the credentials in the appropriate section. When you\'ve done that, this page will prompt you for your admin credentials.</p>');
}

$name = @$_POST['username'];
$pass = @$_POST['pass'];
$invalid_login = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($name || $pass)) {
	if (!ftr_verify_csrf()) {
		http_response_code(403);
		$invalid_login = true;
	} elseif (hash_equals((string)$options->admin_credentials['username'], (string)$name) && hash_equals((string)$options->admin_credentials['password'], (string)$pass)) {
		session_regenerate_id(true);
		// Authentication successful - set session and rotate CSRF token
		$_SESSION['auth'] = 1;
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		if (isset($_POST['redirect']) && preg_match('/^[0-9a-z\-]+$/', $_POST['redirect'])) {
			header('Location: '.$_POST['redirect'].'.php');
		} else {
			header('Location: index.php');
		}
		exit;
	} else {
		$invalid_login = true;
	}
}
?>
<!DOCTYPE html>
<html>
<head><title>Login</title></head>
<body>
<?php if ($invalid_login) echo '<p><strong>Invalid login, please try again.</strong> If you can\'t remember your admin credentials, open your <tt>custom_config.php</tt> and you\'ll find them in there.</p>'; ?>
<form method="post" action="login.php">
<?php echo ftr_csrf_field(); ?>
<?php if (isset($_GET['redirect'])) echo '<input type="hidden" name="redirect" value="'.htmlspecialchars($_GET['redirect'], ENT_QUOTES, 'UTF-8').'" />'; ?>
<label>Username: <input type="text" name="username" value="<?php echo htmlspecialchars((string)@$_POST['username'], ENT_QUOTES, 'UTF-8'); ?>" /></label>
<label>Password: <input type="password" name="pass" /></label>
<input type="submit" name="submit" value="Log In" />
</form>
</body>
</html>