# 部署边界说明

## 1. 公网服务器部署目录

保留在项目根目录的文件，属于公网服务器：

- `auth_session.php`
- `curlGetSyauInfo.php`
- `feedbackApi.php`
- `feedback_schema.sql`
- `courseCommentApi.php`
- `course_comment_schema.sql`
- `loginApi.php`
- `LessonTable/`

这些文件负责：

- 前端页面与 H5 构建产物
- 外网数据库访问
- 反馈系统
- 课程评论系统
- 手动登录中转
- 课表缓存与在线课表获取

## 2. 114 服务器部署目录

`114内网/` 文件夹中的文件属于 114 服务器部署包：

- `114内网/index.php`
- `114内网/auth_session.php`
- `114内网/student_profile.php`
- `114内网/ocr.py`
- `114内网/LessonScheduleData.php`
- `114内网/README.md`

部署时，将 `114内网/` 目录中的文件上传到 114 服务器的 `LessonSchedule` 目录下。

线上目标示例：

- `https://syauinfo.syau.edu.cn/LessonSchedule/index.php`
- `https://syauinfo.syau.edu.cn/LessonSchedule/student_profile.php`

## 3. 注意

- 公网和 114 两端都要配置相同的环境变量：`LESSON_SCHEDULE_AUTH_SECRET`
- 公网服务器上的 `loginApi.php` 应通过 `LESSON_SCHEDULE_PROFILE_API_URL` 指向 114 上的 `student_profile.php`
