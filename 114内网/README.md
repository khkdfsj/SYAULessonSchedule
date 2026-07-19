# 沈阳农业大学个人信息接口

## 1. 目录结构

- `student_profile.php`
  - 接口入口文件
  - 已内置登录、验证码、challenge、个人信息解析逻辑
- `ocr.py`
  - OCR 验证码识别脚本
  - 只有在请求里传 `enable_ocr: true` 时才会调用
- `README.md`
  - 当前这份中文部署和使用说明

## 2. 接口返回

接口返回以下 10 个字段：

| 中文字段 | 返回字段 |
| --- | --- |
| 学号 | `student_number` |
| 姓名 | `full_name` |
| 年级 | `grade` |
| 院系 | `department` |
| 专业 | `major` |
| 入学年级 | `admission_grade` |
| 学制类型 | `schooling_type` |
| 性别 | `gender` |
| 民族 | `ethnicity` |
| 身份证号 | `id_number` |

## 3. 部署

### 3.1 网络要求

- 服务器必须能访问校园内网
- 必须能访问：
  - `https://pass.syau.edu.cn/tpass`
  - `http://10.2.0.47`

### 3.2 PHP 要求

建议使用：

- PHP `7.4` 或更高版本
- 必须开启扩展：
  - `curl`
  - `openssl`
  - `dom`
  - `json`
- 建议开启扩展：
  - `fileinfo`

### 3.3 OCR 依赖

如果你只打算人工输入验证码，可以完全不使用 OCR。

如果你要使用 `enable_ocr: true`，需要安装：

- Python `3.8` 或更高版本
- Python 包：

```bash
python -m pip install ddddocr
```

如果你的环境里没有 `python` 命令，只有 `py` 命令：

```bash
py -3 -m pip install ddddocr
```

安装完成后可测试：

```bash
python ocr.py --check
```

如果返回 `OK`，说明 OCR 环境正常。

注意：当前 `student_profile.php` 默认执行 `python ocr.py`。如果你的服务器只能用 `py -3`，请把 `student_profile.php` 里这一行：

```php
$command = 'python ' . escapeshellarg($this->ocrScriptPath) . ' ' . escapeshellarg($path) . ' 2>&1';
```

改成：

```php
$command = 'py -3 ' . escapeshellarg($this->ocrScriptPath) . ' ' . escapeshellarg($path) . ' 2>&1';
```

## 4. 部署步骤

1. 把整个 `example` 文件夹放到网站目录下
2. 保证 `student_profile.php` 和 `ocr.py` 在同一目录
3. 确保 PHP 扩展已开启
4. 如果要用 OCR，再安装 Python 和 `ddddocr`
5. 使用 JSON `POST` 调用：
   - `http://你的域名/example/student_profile.php`

## 5. 请求方式

- 请求方法：`POST`
- 请求头：`Content-Type: application/json`
- 请求体字段：

| 字段名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `account` | string | 是 | 统一身份认证账号 |
| `password` | string | 是 | 统一身份认证密码 |
| `enable_ocr` | boolean | 否 | 默认 `false`，只有验证码阶段才生效 |
| `captcha_token` | string | 否 | 上一次验证码响应返回的 token |
| `captcha_code` | string | 否 | 手动输入的验证码 |

## 6. 调用流程

### 6.1 第一次请求

```json
{
  "account": "",
  "password": "your_password"
}
```

第一次请求可能出现两种情况：

- 登录成功，直接返回个人信息
- 需要验证码，返回 `409`

### 6.2 第二次请求

手动输入验证码：

```json
{
  "account": "",
  "password": "your_password",
  "captcha_token": "5a7e8f7a61311d7f57f731f67bbaf1f8",
  "captcha_code": "7g3k"
}
```

使用 OCR：

```json
{
  "account": "",
  "password": "your_password",
  "captcha_token": "5a7e8f7a61311d7f57f731f67bbaf1f8",
  "enable_ocr": true
}
```

说明：

- `enable_ocr` 默认不开启
- OCR 只识别一次，不会自动循环重试
- 这样做是为了减少验证码多次识别错误带来的封号风险

## 7. 成功响应示例

HTTP `200`

```json
{
  "code": 200,
  "message": "ok",
  "data": {
    "account": "",
    "profile": {
      "student_number": "",
      "full_name": "",
      "grade": "",
      "department": "",
      "major": "",
      "admission_grade": "",
      "schooling_type": "",
      "gender": "",
      "ethnicity": "",
      "id_number": ""
    }
  }
}
```

## 8. 验证码响应示例

HTTP `409`

```json
{
  "code": 409,
  "message": "请输入验证码",
  "data": {
    "requires_captcha": true,
    "captcha_token": "5a7e8f7a61311d7f57f731f67bbaf1f8",
    "captcha_image_base64": "/9j/4AAQSkZJRgABAQAAAQABAAD...",
    "captcha_image_mime_type": "image/jpeg",
    "captcha_expires_in": 300,
    "ocr_attempted": false
  }
}
```

前端显示图片时，拼接为：

```text
data:${captcha_image_mime_type};base64,${captcha_image_base64}
```

## 9. 密码错误响应示例

HTTP `401`

```json
{
  "code": 401,
  "message": "连续登录失败8次，账号将被锁定1分钟，剩余次数7",
  "data": {
    "remaining_attempts": 7
  }
}
```

这里的 `message` 优先返回学校页面真实的 `errormsg`。

## 10. 账号锁定响应示例

HTTP `423`

```json
{
  "code": 423,
  "message": "账号已锁定，请1分钟后重试",
  "data": {
    "account_locked": true
  }
}
```

## 11. 使用建议

前端建议处理：

1. 先调用一次 `student_profile.php`
2. 如果返回 `200`，直接使用 `data.profile`
3. 如果返回 `409`，把验证码图片显示给用户
4. 用户手填验证码，或者显式选择 OCR
5. 再次调用同一个接口
6. 如果返回 `401` 或 `423`，直接显示 `message`

## 12. 安全与清理

- challenge token 默认 5 分钟过期
- OCR 临时验证码图片会在使用后删除
- 过期 challenge 文件会自动清理
- 验证码文件名和 token 都是随机值，避免多人并发冲突
- 不会自动反复提交验证码，避免增加账号锁定风险
- 身份证号返回真实值，不是脱敏值

## 13. 常见问题

### 13.1 为什么返回 502

常见原因：

- 服务器不在校园网内
- PHP 的 `curl` 或 `openssl` 没开启
- 学校登录页结构变化
- 你开启了 OCR，但服务器没有安装 Python 或 `ddddocr`

### 13.2 为什么 OCR 失败

因为 OCR 只做一次识别，复杂验证码可能识别错误。失败后建议切回人工输入。

## 14. 快速测试

PowerShell 测试：

```powershell
$body = '{"account":"","password":""}'
$body | php .\student_profile.php
```

如果返回 `400` 或 `409` 之类的 JSON，说明接口脚本已经能正常执行。