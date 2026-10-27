-- Glow by Petra Jahodová – rezervační systém
-- MySQL 8.4, kódování utf8mb4. Časy jsou v místním čase (Europe/Prague), bez převodu na UTC.
-- Import: phpMyAdmin (dbadmin.r4.active24.cz) → databáze WDRv2ch8 → Import.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS bookings (
  id              CHAR(36)      NOT NULL,                       -- UUID
  kind            ENUM('standard','wedding') NOT NULL DEFAULT 'standard',
  status          ENUM('pending','confirmed','rejected','cancelled','awaiting_deposit') NOT NULL DEFAULT 'pending',
  source          ENUM('web','admin') NOT NULL DEFAULT 'web',

  start_at        DATETIME      NOT NULL,                       -- začátek (u svatby den D 00:00)
  end_at          DATETIME      NOT NULL,                       -- konec (u svatby den D 23:59)
  persons         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  services_json   JSON          NULL,                           -- ["denni","uces-jednoduchy"] – id služeb z includes/services.php
  service_label   VARCHAR(255)  NOT NULL,                       -- čitelný název, např. "Denní líčení + Účes – jednoduchá úprava"
  duration_min    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  price_total     INT UNSIGNED  NULL,                           -- Kč (snapshot v době objednávky)
  price_label     VARCHAR(64)   NULL,                           -- např. "od 1 000 Kč"

  customer_name   VARCHAR(120)  NOT NULL,
  customer_email  VARCHAR(190)  NOT NULL,
  customer_phone  VARCHAR(40)   NOT NULL,
  note            TEXT          NULL,
  gdpr_at         DATETIME      NULL,                           -- kdy zákaznice odsouhlasila zpracování údajů

  -- svatba
  wedding_place   VARCHAR(190)  NULL,
  ceremony_time   TIME          NULL,
  ready_by        TIME          NULL,
  persons_makeup  TINYINT UNSIGNED NULL,
  persons_hair    TINYINT UNSIGNED NULL,
  trial_interest  TINYINT(1)    NULL,
  deposit_vs      VARCHAR(10)   NULL,                           -- variabilní symbol zálohy (číslo poptávky)
  deposit_paid_at DATETIME      NULL,

  -- tokeny do odkazů v e-mailech
  cancel_token    CHAR(40)      NOT NULL,
  decide_token    CHAR(40)      NOT NULL,                       -- potvrzení/odmítnutí Petrou z e-mailu

  -- workflow
  decided_at      DATETIME      NULL,
  reject_reason   TEXT          NULL,
  reminder_sent   TINYINT(1)    NOT NULL DEFAULT 0,             -- připomínka zákaznici 24 h předem
  owner_nudged_at DATETIME      NULL,                           -- připomínka Petře (nepotvrzeno / záloha nepřišla)
  gcal_event_id   VARCHAR(190)  NULL,

  created_at      DATETIME      NOT NULL,
  updated_at      DATETIME      NOT NULL,

  PRIMARY KEY (id),
  KEY idx_time (start_at, end_at),
  KEY idx_status (status),
  KEY idx_email (customer_email),
  KEY idx_vs (deposit_vs)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Blokace (dovolená, svatba, soukromá akce). source=gcal = načteno z Google Kalendáře (obousměrná synchronizace).
CREATE TABLE IF NOT EXISTS blocked_slots (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  start_at        DATETIME      NOT NULL,
  end_at          DATETIME      NOT NULL,
  reason          VARCHAR(190)  NULL,
  source          ENUM('admin','gcal') NOT NULL DEFAULT 'admin',
  gcal_event_id   VARCHAR(190)  NULL,
  created_at      DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY idx_time (start_at, end_at),
  UNIQUE KEY uq_gcal (gcal_event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Nastavení ukládaná z adminu (heslo adminu po prvním spuštění, čítač VS, poslední sync kalendáře…)
CREATE TABLE IF NOT EXISTS settings (
  `key`           VARCHAR(64)   NOT NULL,
  `value`         TEXT          NULL,
  updated_at      DATETIME      NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Recenze klientek z webu. Tabulku si při prvním použití založí includes/reviews.php (reviewsEnsureSchema) včetně
-- 4 původních recenzí – tady je jen pro úplnost schématu. pending = čeká na schválení, approved = na webu, rejected = nezveřejněná.
CREATE TABLE IF NOT EXISTS reviews (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  name            VARCHAR(80)   NOT NULL,
  email           VARCHAR(190)  NULL,                           -- nepovinný, nikdy se nezveřejňuje
  service         VARCHAR(80)   NULL,                           -- z REVIEW_SERVICES v includes/reviews.php
  rating          TINYINT UNSIGNED NOT NULL DEFAULT 5,          -- 1–5
  text            TEXT          NOT NULL,
  decide_token    CHAR(40)      NOT NULL,                       -- schválení/odmítnutí z e-mailu
  ip_hash         CHAR(16)      NULL,
  created_at      DATETIME      NOT NULL,
  decided_at      DATETIME      NULL,
  PRIMARY KEY (id),
  KEY idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Jednoduchý log odeslaných e-mailů a chyb (pro ladění bez přístupu k serveru).
CREATE TABLE IF NOT EXISTS log (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  level           ENUM('info','error') NOT NULL DEFAULT 'info',
  event           VARCHAR(64)   NOT NULL,
  booking_id      CHAR(36)      NULL,
  message         TEXT          NULL,
  created_at      DATETIME      NOT NULL,
  PRIMARY KEY (id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
