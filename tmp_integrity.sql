-- Which practices do the remaining cases_cache rows belong to?
SELECT c.practice_id, p.practice_name, COUNT(*) AS cnt
FROM cases_cache c
LEFT JOIN practices p ON p.id = c.practice_id
GROUP BY c.practice_id, p.practice_name;

-- Orphan checks (all must be 0)
SELECT 'orphan_practice_users_practice' AS what, COUNT(*) AS cnt FROM practice_users pu
  LEFT JOIN practices p ON p.id = pu.practice_id WHERE p.id IS NULL
UNION ALL
SELECT 'orphan_practice_users_user', COUNT(*) FROM practice_users pu
  LEFT JOIN users u ON u.id = pu.user_id WHERE u.id IS NULL
UNION ALL
SELECT 'orphan_subscriptions', COUNT(*) FROM subscriptions s
  LEFT JOIN users u ON u.id = s.owner_user_id WHERE u.id IS NULL
UNION ALL
SELECT 'orphan_cases_cache_practice', COUNT(*) FROM cases_cache c
  LEFT JOIN practices p ON p.id = c.practice_id WHERE p.id IS NULL
UNION ALL
SELECT 'orphan_case_updates_case', COUNT(*) FROM case_updates cu
  LEFT JOIN cases_cache c ON c.id = cu.case_id WHERE c.id IS NULL
UNION ALL
SELECT 'orphan_case_activity_log_case', COUNT(*) FROM case_activity_log cal
  LEFT JOIN cases_cache c ON c.id = cal.case_id WHERE c.id IS NULL
UNION ALL
SELECT 'orphan_case_lab_assignment_periods_case', COUNT(*) FROM case_lab_assignment_periods clp
  LEFT JOIN cases_cache c ON c.id = clp.case_id WHERE c.id IS NULL
UNION ALL
SELECT 'orphan_phi_access_log_practice', COUNT(*) FROM phi_access_log pal
  LEFT JOIN practices p ON p.id = pal.practice_id WHERE p.id IS NULL
UNION ALL
SELECT 'orphan_data_exports_practice', COUNT(*) FROM data_exports de
  LEFT JOIN practices p ON p.id = de.practice_id WHERE p.id IS NULL
UNION ALL
SELECT 'practices_with_no_owner', COUNT(*) FROM practices p
  LEFT JOIN practice_users pu ON pu.practice_id = p.id AND pu.is_owner = 1
  WHERE pu.practice_id IS NULL
UNION ALL
SELECT 'orphan_user_activity_log_user', COUNT(*) FROM user_activity_log ual
  LEFT JOIN users u ON u.id = ual.user_id WHERE u.id IS NULL
UNION ALL
SELECT 'orphan_sessions_user', COUNT(*) FROM sessions s
  LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL
UNION ALL
SELECT 'remaining_e2e_or_audit_users (should be 1: e2e.test@dentatrak.com)', COUNT(*) FROM users
  WHERE email LIKE 'e2e%' OR email LIKE 'audit.%'
UNION ALL
SELECT 'remaining_seeded_practices (should be 0)', COUNT(*) FROM practices WHERE practice_name LIKE 'Seeded Practice %';
