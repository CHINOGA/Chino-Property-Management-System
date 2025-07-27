-- Update database schema for Property Management System

USE pms_db;

-- Add email column to tenants table if it doesn't exist
ALTER TABLE tenants ADD COLUMN IF NOT EXISTS email VARCHAR(100) NOT NULL AFTER phone;

-- Remove rent column from leases table if it exists
ALTER TABLE leases DROP COLUMN IF EXISTS rent;

-- Add status column to leases table if it doesn't exist
ALTER TABLE leases ADD COLUMN IF NOT EXISTS status ENUM('active', 'expired', 'terminated') NOT NULL DEFAULT 'active' AFTER lease_document;
