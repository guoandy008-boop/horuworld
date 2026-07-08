# 部署说明

这是一个轻量 PHP + HTML 的记账 Web App，可以直接部署到支持 PHP 的服务器目录中。

## 1. 准备配置

复制配置模板：

```bash
cp config.example.php config.php
```

然后修改 `config.php`：

- `CRON_TOKEN`：定时任务访问口令
- `ADMIN_PASS`：后台管理密码
- `FEISHU_WEBHOOK`：飞书群机器人 Webhook，可留空
- `FEISHU_APP_ID` / `FEISHU_APP_SECRET`：飞书自建应用凭证，可留空
- `FEISHU_OWNER_EMAIL`：账本主人邮箱，可留空

`config.php` 已被 `.gitignore` 忽略，不要提交到 GitHub。

## 2. 运行目录

将以下文件上传到站点目录：

```text
index.html
auth.php
api.php
feishu.php
report.php
backup.php
admin.php
config.php
```

首次注册后，项目会自动生成：

```text
users.json
userdata/
backups/
feishu_bind.json
feishu_bindcodes.json
feishu_seen.json
report_run.log
```

这些都是运行数据，不应提交到 GitHub。

## 3. 定时任务

每日小报：

```text
https://your-domain.example/report.php?token=你的CRON_TOKEN
```

每日备份：

```text
https://your-domain.example/backup.php?token=你的CRON_TOKEN
```

## 4. 公开前检查

- 确认 `config.php` 没有被提交
- 确认 `users.json`、`userdata/`、`backups/` 没有被提交
- 确认线上 `ADMIN_PASS` 不是默认值
- 确认飞书 App Secret、Webhook、定时任务 token 没有出现在公开仓库

