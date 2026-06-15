@echo off
:: AuToJs.com 72-hour temp file cleanup
:: 由 Windows 计划任务调用：每 6 小时执行一次

cd /d D:\phpstudy_pro\WWW\www.AuToJs.com
"D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe" think cleanup --hours=72
