-- ============================================================
-- AuToJs 数据库结构 (MySQL 8.0.12 / utf8mb4)
-- 适用于 ThinkPHP 6.1，数据库名: autojs
-- ============================================================

CREATE DATABASE IF NOT EXISTS `autojs`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `autojs`;

-- ============================================================
-- 1. 转换记录表
-- ============================================================
DROP TABLE IF EXISTS `atj_conversion_log`;
CREATE TABLE `atj_conversion_log` (
    `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT       COMMENT '主键',
    `user_ip`       VARCHAR(45)      NOT NULL DEFAULT ''           COMMENT '用户 IP',
    `src_name`      VARCHAR(255)     NOT NULL DEFAULT ''           COMMENT '源文件原始名',
    `src_size`      INT UNSIGNED     NOT NULL DEFAULT 0            COMMENT '源文件字节数',
    `out_name`      VARCHAR(255)     NOT NULL DEFAULT ''           COMMENT '输出文件名',
    `out_size`      INT UNSIGNED     NOT NULL DEFAULT 0            COMMENT '输出字节数',
    `batch_id`      VARCHAR(40)      NOT NULL DEFAULT ''           COMMENT '批次号',
    `mode`          VARCHAR(10)      NOT NULL DEFAULT 'single'     COMMENT 'single / batch',
    `status`        TINYINT UNSIGNED NOT NULL DEFAULT 1            COMMENT '1成功 0失败',
    `error_msg`     VARCHAR(500)     NOT NULL DEFAULT ''           COMMENT '错误信息',
    `note_count`    INT UNSIGNED     NOT NULL DEFAULT 0            COMMENT '音符数量',
    `bpm`           INT UNSIGNED     NOT NULL DEFAULT 0            COMMENT 'BPM',
    `is_json`       TINYINT UNSIGNED NOT NULL DEFAULT 1            COMMENT '1=Json 0=ABC',
    `cost_ms`       INT UNSIGNED     NOT NULL DEFAULT 0            COMMENT '转换耗时(毫秒)',
    `create_time`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP           COMMENT '创建时间',
    `update_time`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `delete_time`   DATETIME         NULL DEFAULT NULL             COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_ip`     (`user_ip`),
    KEY `idx_batch_id`    (`batch_id`),
    KEY `idx_status`      (`status`),
    KEY `idx_create_time` (`create_time`),
    KEY `idx_mode`        (`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='转换记录';

-- ============================================================
-- 2. 管理员表
-- ============================================================
DROP TABLE IF EXISTS `atj_admin`;
CREATE TABLE `atj_admin` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT            COMMENT '主键',
    `username`         VARCHAR(32)   NOT NULL DEFAULT ''                COMMENT '用户名',
    `password`         VARCHAR(64)   NOT NULL DEFAULT ''                COMMENT '密码(md5(salt+pwd))',
    `real_name`        VARCHAR(32)   NOT NULL DEFAULT ''                COMMENT '真实姓名',
    `status`           TINYINT       NOT NULL DEFAULT 1                 COMMENT '1启用 0禁用',
    `last_login_time`  DATETIME      NULL DEFAULT NULL                  COMMENT '最后登录时间',
    `last_login_ip`    VARCHAR(45)   NOT NULL DEFAULT ''                COMMENT '最后登录IP',
    `create_time`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP            COMMENT '创建时间',
    `update_time`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `delete_time`      DATETIME      NULL DEFAULT NULL                  COMMENT '软删除时间',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员';

-- ============================================================
-- 3. 初始化默认管理员 (用户名: admin, 密码: admin888)
-- 密码计算公式: md5('au2js_2024_salt' . 'admin888')
-- ============================================================
INSERT INTO `atj_admin` (`username`, `password`, `real_name`, `status`)
VALUES ('admin', '74384c419e09d0a900f3c9863d65f58d', '超级管理员', 1)
ON DUPLICATE KEY UPDATE `username` = `username`;

-- ============================================================
-- 4. IP 限流记录（可选 - 如果需要存库而不是用 cache）
-- ============================================================
DROP TABLE IF EXISTS `atj_rate_limit`;
CREATE TABLE `atj_rate_limit` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键',
    `user_ip`      VARCHAR(45)     NOT NULL DEFAULT ''     COMMENT 'IP',
    `action`       VARCHAR(32)     NOT NULL DEFAULT ''     COMMENT '行为',
    `hit_time`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '命中时间',
    PRIMARY KEY (`id`),
    KEY `idx_user_ip_time` (`user_ip`, `hit_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='IP 限流记录';
