# SYAULessonSchedule（沈农课程表）

沈阳农业大学企业微信"我的课表"应用：uni-app(Vue3) H5 前端 + PHP 后端，支持白天在线、夜间缓存自动切换、课程群、公告推送、学生调课计划、开学日期后端统一管理等。

> 仓库为私有项目，包含完整未编译源码。PHP 文件中含数据库凭据（当前为私有仓库，未脱敏）。

## 技术栈

- 前端：uni-app（Vue3 + Vite），H5 平台，HBuilderX 5.x 构建
- 后端：PHP（bm 服务器：PHP 7.4），MySQL（bm 本机，库 `LessonTable`）
- 部署：bm 服务器 Nginx `/www/server/nginx/html/LessonSchedule/`（线上 `https://debug.91nongye.cn/LessonSchedule/`）

## 目录结构

```
├── LessonTable/            # uni-app 前端源码（HBuilderX 项目，源码在根目录）
│   ├── pages/              # 页面（index 课表 / settings 设置 / devlog 日志 / feedback 反馈等）
│   ├── api/ utils/ data/   # 接口封装 / 工具 / 开发日志
│   └── manifest.json       # 应用配置（appid __UNI__132B5B2、版本号）
├── 114内网/                # 114 服务器部署包（LessonScheduleData.php 等）
├── curlGetSyauInfo.php     # 前端数据接口（课表获取 + 下发 semesterStartDate/appVersion）
├── scheduleAdjustmentApi.php / schedule_adjustment_lib.php  # 调课管理与学生课表规则合并
├── getTodayCourse.php      # 今日课程接口（校历服务接入）
├── feedbackApi.php / courseCommentApi.php / courseGroupApi.php / loginApi.php / announcementApi.php  # 其余后端接口
└── update-log.html         # 用户版开发日志
```

## 本地开发

```bash
cd LessonTable
npm install
npm run dev        # vite dev server，接口经 /h5api 代理到线上 debug.91nongye.cn
```

> ⚠️ 正式构建必须用 **HBuilderX**（`npm run build` 仅生成空壳）：打开 LessonTable 项目 → 发行 → 网站-H5手机版，
> 或 `D:\HBuilderX\cli.exe publish --platform web --project LessonTable`（需登录且 appid 归属当前账号）。

## 构建产物与部署

- 正式构建产物：`LessonTable/unpackage/dist/build/web/`（index.html + assets/，主包约 250KB）
- 部署：备份线上 → 上传 `index.html` + `assets/` + 变更的 PHP 文件到 bm `/www/server/nginx/html/LessonSchedule/`
- 线上有副本目录 `/kebiao`

## 版本管理（三位版本号 主.次.修订）

- **强制更新机制**：后端 `curlGetSyauInfo.php` 的 `APP_VERSION` 为权威版本，响应下发 `appVersion`；
  前端每次进入校验，本地落后则强制弹窗更新（刷新带 `?v=<版本>` 缓存破坏）
- **发版三处同步**：`LessonTable/utils/app.js` + `manifest.json`（versionName/versionCode）+ 后端 `APP_VERSION`
- **日志规则**：修订位小更新（0.4.x）只写 commit 信息；次/主版本升级（0.5.0、1.0.0）才集中撰写
  `update-log.html` + `LessonTable/data/devlog.js`（用户版脱敏）

## 开学日期（后端统一管理）

- 权威来源：bm 上 `syau-calendar` 校历服务（`/opt/syau-calendar`，改 `.env` 的 `MANUAL_START_DATE` 后重启生效，当前 2026-08-24）
- 下发链路：`curlGetSyauInfo.php` 读取校历服务 → 响应 `semesterStartDate`/`semesterMark` → 前端强制采用
- 数据来源由程序自动管理，用户不可手动切换；白天使用在线数据，22:00至次日06:00使用缓存

## 学生调课计划

- 管理员可在设置页进入“调课管理”，按学期、学生类型、年级、来源周/星期和目标周/星期创建多条规则。
- 已发布规则只在课表响应阶段动态生成调课课程，不改写学校原始课表和数据库课表缓存；停止计划后自动恢复。
- 本科生、研究生按学号身份位区分，教师不应用调课规则。
- 调课课程使用蓝色标识；若与目标日期原课程重叠，继续使用现有红色冲突提示和课程切换逻辑。

## GitHub 分支/tag 流程（协作者规范）

- `main` = 主线包（= 服务器正式版本），所有改动最终合并到 `main`
- `test` = 下一版本管理员内测包；凡递增版本号，必须先部署 `test` 并经项目负责人明确验收
- 每个协作者使用**自己的个人分支**（以自己的用户名命名，如 `bailun`、`wttmf`），
  不共用、不使用他人的分支
- 带版本号的发布流程：个人分支开发 → 合并 `test` → 管理员内测 → 明确批准 → 合并 `main` → 正式部署 → 打 tag `vX.Y.Z`
- 不递增版本号的小补丁可在验证和备份后直接上线；涉及入口、缓存、认证、构建、数据库或 Nginx 的补丁仍必须先内测
- 测试版部署到独立的 `/LessonSchedule-test/`，由现有管理员身份校验；测试期间不得修改正式后端 `APP_VERSION`
- 仅当候选版本高于正式版本时，已认证管理员才从正式入口自动转入测试版；版本一致或没有候选包时，所有人使用正式版
- 静态资源发布只允许合并新增文件，禁止清空线上 `assets/`，必须保留上一正式版所需的哈希资源
- 完整规则见 `RELEASE_WORKFLOW.md`

- 正式发布命令示例：
  ```
  git checkout main && git merge --ff-only test && git tag vX.Y.Z && git push origin main test vX.Y.Z
  ```
- 回滚：`git checkout vX.Y.Z`（tag 即版本回滚点）
- 小更新（修订位 0.4.x）只写 commit 日志；大版本升级（0.5.0、1.0.0）才集中撰写开发日志
