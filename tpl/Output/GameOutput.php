<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="<?php echo htmlspecialchars($this->_['pulsecore_page_language'], ENT_QUOTES, 'UTF-8'); ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="dark">
	<meta name="theme-color" content="#0a1018">
	<title>PULSE//CORE</title>
	<style>html,body{margin:0;min-height:100%;background:#0a1018}body{padding:0}</style>
</head>
<body><?php echo $this->_['pulsecore_content']; ?></body>
</html>
