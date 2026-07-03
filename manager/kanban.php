<?php
// manager/kanban.php — Manager Kanban Board
// Manager sees ALL leads (same as admin) on the kanban view.
// We simply require manager role then forward to the shared kanban logic.
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin','manager']);

// All kanban rendering logic lives in admin/kanban.php
// (which already handles both admin and manager via requireLogin + role-neutral $cond)
require __DIR__ . '/../admin/kanban.php';