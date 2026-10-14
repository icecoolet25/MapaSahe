-- MapaSahe database. Import this once in phpMyAdmin (Import tab), or
-- paste it into the SQL tab. Safe to run again: it never drops data.

CREATE DATABASE IF NOT EXISTS mapasahe
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE mapasahe;

-- Accounts. role is 'customer' or 'admin'.
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username    VARCHAR(50)  NOT NULL,
  email       VARCHAR(190) NOT NULL,
  password    VARCHAR(255) NOT NULL,
  role        VARCHAR(20)  NOT NULL DEFAULT 'customer',
  created_at  DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

-- One row per planner search.
CREATE TABLE IF NOT EXISTS search_logs (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at           DATETIME     NOT NULL,
  user_id              INT UNSIGNED NULL,
  origin_node_id       VARCHAR(64)  NOT NULL,
  origin_name          VARCHAR(190) NOT NULL,
  destination_node_id  VARCHAR(64)  NOT NULL,
  destination_name     VARCHAR(190) NOT NULL,
  status               VARCHAR(16)  NOT NULL,   -- ok | no_route | invalid
  load_ms              DOUBLE       NOT NULL,   -- dataset load + graph build
  total_ms             DOUBLE       NOT NULL,   -- whole request
  hybrid_cost_min      DOUBLE       NULL,
  hybrid_edges         INT          NULL,
  PRIMARY KEY (id),
  KEY ix_search_created (created_at),
  KEY ix_search_user (user_id, created_at),
  KEY ix_search_pair (origin_node_id, destination_node_id)
) ENGINE=InnoDB;

-- One row per model per search: Dijkstra, A*, GBFS, Hybrid.
CREATE TABLE IF NOT EXISTS model_runs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  search_id       INT UNSIGNED NOT NULL,
  model           VARCHAR(16)  NOT NULL,
  found           TINYINT(1)   NOT NULL,
  duration_ms     DOUBLE       NOT NULL,
  nodes_explored  INT          NOT NULL,
  cost_min        DOUBLE       NULL,
  edge_count      INT          NOT NULL,
  PRIMARY KEY (id),
  KEY ix_runs_search (search_id),
  KEY ix_runs_model (model)
) ENGINE=InnoDB;

-- The stops each search touched: its origin, its destination, and the
-- stops along the suggested route ("via").
CREATE TABLE IF NOT EXISTS search_stops (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  search_id  INT UNSIGNED NOT NULL,
  node_id    VARCHAR(64)  NOT NULL,
  stop_name  VARCHAR(190) NOT NULL,
  role       VARCHAR(12)  NOT NULL,   -- origin | destination | via
  PRIMARY KEY (id),
  KEY ix_stops_search (search_id),
  KEY ix_stops_node (node_id)
) ENGINE=InnoDB;

-- Trips a user has saved.
CREATE TABLE IF NOT EXISTS saved_routes (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id              INT UNSIGNED NOT NULL,
  origin_node_id       VARCHAR(64)  NOT NULL,
  origin_name          VARCHAR(190) NOT NULL,
  destination_node_id  VARCHAR(64)  NOT NULL,
  destination_name     VARCHAR(190) NOT NULL,
  created_at           DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY ix_saved_user (user_id)
) ENGINE=InnoDB;

-- The admin history log.
CREATE TABLE IF NOT EXISTS events (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at  DATETIME     NOT NULL,
  type        VARCHAR(32)  NOT NULL,
  user_id     INT UNSIGNED NULL,
  details     VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ix_events_created (created_at),
  KEY ix_events_type (type)
) ENGINE=InnoDB;

-- To make an account an admin: register it on the Sign up page first,
-- then run this with that account's email.
--
--   UPDATE users SET role = 'admin' WHERE email = 'you@example.com';
