# 123云盘资源分享系统 🚀

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

> 基于123云盘OpenAPI构建的智能资源管理与分享系统

![](https://via.placeholder.com/800x400.png?text=System+Preview)

## 🌟 核心功能

### 🔍 智能文件搜索
- 多维度匹配算法（支持全字/开头/关键词匹配）
- 动态相关性评分（视频文件自动加权）
- 分页加载机制（单次请求上限100条）

### 🔗 安全分享系统
- 灵活分享策略（密码保护/有效期设置）
- 智能流量控制（免登录流量包开关）
- 操作频率限制（防止API滥用）

### ⚙️ 系统特性
- 模块化代码架构（配置/功能/逻辑分离）
- 完善的错误处理机制（日志记录/错误抑制）
- 响应式UI设计（移动端优先适配）
- 高性能动画引擎（WebGL加速）

## 🛠️ 快速部署

### 环境要求
- PHP ≥7.4（推荐8.0+）
- CURL扩展
- JSON扩展

### 安装步骤
```bash
# 克隆仓库
git clone https://github.com/52nfw/123pan.git
# 配置环境
cp .env.example .env
```

### 关键配置
```php
// API鉴权
define('CLIENT_ID', 'your-client-id');      // 替换为真实ID
define('CLIENT_SECRET', 'your-secret');     // 替换为真实密钥

// 分享策略
define('SHARE_EXPIRE', 7);       // 有效期设置（0=永久）
define('MAX_SELECT', 30);        // 最大可选文件数
```

## 🚨 注意事项
1. API凭证需在[123云盘开放平台](https://www.123pan.com/developer)申请
2. 生产环境务必设置 `DISPLAY_ERRORS = 0
3. 文件排序算法支持自定义评分规则
4. 分享冷却时间可配置（单位：秒）

## 🤝 贡献指南
欢迎通过 Issue 提交改进建议或通过 PR 参与开发：
1. Fork 项目仓库
2. 创建特性分支 (`git checkout -b feature/awesome`)
3. 提交更改 (`git commit -m 'Add awesome feature'`)
4. 推送分支 (`git push origin feature/awesome`)
5. 发起 Pull Request

## 📜 开源协议
本项目采用 [MIT License](LICENSE)，请遵守123云盘API使用条款。
