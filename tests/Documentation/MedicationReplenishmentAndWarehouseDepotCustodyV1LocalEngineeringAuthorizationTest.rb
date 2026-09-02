# frozen_string_literal: true

require 'minitest/autorun'

class MedicationReplenishmentAndWarehouseDepotCustodyV1LocalEngineeringAuthorizationTest < Minitest::Test
  ROOT = File.expand_path('../..', __dir__)
  PATH = File.join(
    ROOT,
    'docs/new-simrs-rebuild/phase-1/MEDICATION_REPLENISHMENT_AND_WAREHOUSE_DEPOT_CUSTODY_V1_LOCAL_ENGINEERING_AUTHORIZATION_2026-09-02.md'
  )

  def setup
    @authorization = File.binread(PATH)
  end

  def test_product_owner_authority_and_exact_par_e2e_scope_are_bounded
    assert_includes @authorization, 'bounded local implementation present; active security correction and exact-engine acceptance remain open'
    %w[
      PAR-ADM-017 PAR-ADM-030 PAR-PWH-002 PAR-PWH-003 PAR-PWH-005 PAR-PWH-006
      PAR-PWH-011 PAR-PWH-012 PAR-PWH-014 PAR-PWH-016 PAR-PWH-017
    ].each { |id| assert_includes @authorization, "`#{id}`" }
    %w[E2E-11 E2E-10 E2E-02 E2E-03 E2E-04 E2E-14 E2E-16 E2E-09].each do |id|
      assert_includes @authorization, "`#{id}`"
    end
    assert_includes @authorization, 'standing product-owner local-engineering authorization only'
    assert_includes @authorization, 'not procurement, pharmacy, warehouse, clinical, finance-accounting'
    assert_includes @authorization, 'close G0 or G3'
    assert_includes @authorization, 'Commit, push, hosted migration, and deployment remain outside this authorization.'
  end

  def test_canonical_receipt_distribution_and_edit_caveats_are_preserved
    assert_includes @authorization, '`PAR-PWH-002` (`Obat Masuk`) and `PAR-PWH-012` (`Obat Masuk 2`) remain separate parity rows.'
    assert_includes @authorization, 'no consolidation, retirement, or equivalence is claimed'
    assert_includes @authorization, '`PAR-PWH-003` (`Distribusi Obat`) and `PAR-PWH-016` (`Distribusi Antar Depo`)'
    assert_includes @authorization, 'remain separately traceable source rows'
    assert_includes @authorization, '`PAR-PWH-014` (`Edit Transaksi`) maps in V1 to an append-only, independently reviewed correction.'
    assert_includes @authorization, 'proposed teaching-system safety replacement, not a legacy-parity claim'
  end

  def test_exact_roles_and_capabilities_enforce_independent_approval_and_custody
    %w[
      procurement_officer procurement_approver warehouse_receiver warehouse_inventory_controller
      warehouse_inventory_supervisor pharmacy_inventory_controller pharmacist pharmacy_technician admin
    ].each { |role| assert_includes @authorization, "`#{role}`" }
    %w[
      warehouse.purchase-order.create warehouse.purchase-order.submit warehouse.purchase-order.review
      warehouse.receipt.record warehouse.transfer.dispatch warehouse.transfer.accept
      warehouse.return.supplier warehouse.return.unit warehouse.correction.request warehouse.correction.review
    ].each { |capability| assert_includes @authorization, "`#{capability}`" }
    assert_includes @authorization, 'Purchase-order creator and approver must differ'
    assert_includes @authorization, 'source dispatcher and destination acceptor must differ'
    assert_includes @authorization, 'correction requester and correction approver must differ'
    assert_includes @authorization, 'Authority is checked before route-resource lookup'
    assert_includes @authorization, 'Unauthorized users receive no resource-existence disclosure.'
  end

  def test_po_receipt_and_two_sided_custody_have_safe_closed_lifecycles
    assert_match(/DRAFT -> SUBMITTED -> APPROVED -> PARTIALLY_RECEIVED -> FULLY_RECEIVED -> CLOSED/, @authorization)
    assert_includes @authorization, 'creator can never review it, even if the account later gains another role'
    assert_includes @authorization, 'V1 refuses over-receipt'
    assert_includes @authorization, 'SUPPLIER_RECEIPT_AVAILABLE'
    assert_includes @authorization, 'SUPPLIER_RECEIPT_QUARANTINED'
    assert_includes @authorization, 'central OUT + IN_TRANSIT'
    assert_includes @authorization, 'IN_TRANSIT OUT + destination AVAILABLE'
    assert_includes @authorization, 'No one-sided movement is durable.'
    assert_includes @authorization, 'Partial destination acceptance is outside V1'
  end

  def test_returns_corrections_and_stock_cards_are_immutable_and_reconcilable
    assert_includes @authorization, 'supplier return under `PAR-PWH-005`'
    assert_includes @authorization, 'unit/depot return under `PAR-PWH-006`'
    assert_includes @authorization, 'never updated, deleted, hidden, renumbered, or overwritten'
    assert_includes @authorization, 'immutable request plus independent supervisor decision'
    assert_includes @authorization, 'one linked compensating movement set'
    assert_includes @authorization, 'Every stock card is derived only from immutable movements.'
    assert_includes @authorization, 'No negative custody bucket, duplicate movement, duplicate receipt'
    assert_includes @authorization, 'retained source inflow quantity'
    assert_includes @authorization, 'net pharmacy-issued quantity from retained handover/return movements'
    assert_includes @authorization, 'never be used as an unexplained balancing plug'
    assert_includes @authorization, 'A mismatch is a hard integrity failure'
    assert_includes @authorization, 'No user enters a correction balance directly.'
  end

  def test_idempotency_audit_concurrency_recovery_and_exact_engines_are_required
    assert_includes @authorization, 'Same key and same canonical payload recomputes retained evidence and returns the same result.'
    assert_includes @authorization, 'Same key with changed payload fails as `idempotency_key_conflict`.'
    assert_includes @authorization, 'Audit failure rolls back every new row and balance/projection change.'
    assert_includes @authorization.downcase, 'dispatch-versus-pharmacy-handover'
    assert_includes @authorization, 'accept-versus-reject'
    assert_includes @authorization, 'never overspend stock, over-receive a PO, accept and reject the same transfer'
    assert_includes @authorization, 'Synthetic reset removes only the named synthetic warehouse fixture in dependency order'
    assert_includes @authorization, 'Migration rollback refuses while any supplier/warehouse business row'
    assert_includes @authorization, 'Exact PostgreSQL 17 and MySQL 8.4 rehearsal'
    assert_includes @authorization, 'real competing-receipt and dispatch-versus-dispense database waits'
    assert_includes @authorization, 'third-connection custody/control-total readback'
    assert_includes @authorization, 'No evidence record may start as PASS.'
  end

  def test_indonesian_worklists_and_accessibility_are_explicit
    [
      'Master Pemasok', 'Pemesanan Obat', 'Persetujuan PO', 'Penerimaan Gudang Farmasi',
      'Distribusi ke Depo', 'Penerimaan Depo', 'Retur Pemasok', 'Retur Bagian',
      'Kartu Stok Gudang', 'Koreksi Transaksi Stok'
    ].each { |label| assert_includes @authorization, "`#{label}`" }
    assert_includes @authorization, 'semantic tables'
    assert_includes @authorization, 'at least 44-pixel targets'
    assert_includes @authorization, 'move focus to the error summary'
    assert_includes @authorization, 'announce committed state changes'
    assert_includes @authorization, 'No UI may label a submitted PO as approved'
  end

  def test_stocktake_blood_accounting_live_and_delivery_exclusions_are_explicit
    exclusions = @authorization.split('## Explicit V1 exclusions', 2).last
    %w[PAR-PWH-004 PAR-PWH-015 PAR-PWH-023 PAR-PWH-022 PAR-CLN-017 E2E-07].each do |id|
      assert_includes exclusions, "`#{id}`"
    end
    [
      'stocktake', 'blood stock', 'accounts payable/AP', 'accounting journal', 'live integrations',
      'real patient', 'secrets', 'commit', 'push', 'hosted migration', 'deployment'
    ].each { |excluded| assert_includes exclusions.downcase, excluded.downcase }
    assert_includes @authorization, 'must never be implemented as ordinary medicine replenishment'
    assert_includes @authorization, 'must not be described as exact-engine verified, domain-accepted'
  end

  def test_checkpoint_ships_source_without_activating_hosted_warehouse
    assert_includes @authorization, 'Shipping source bytes is not warehouse activation.'
    assert_includes @authorization, '`WAREHOUSE_SCHEMA_MIGRATION_ENABLED=false`'
    assert_includes @authorization, '`2026_09_03_000100_expand_warehouse_teaching_role_access_roster`'
    assert_includes @authorization, '`2026_09_03_000200_create_medication_replenishment_warehouse_custody_tables`'
    assert_includes @authorization, 'No warehouse route, navigation entry, hosted schema, hosted write, or role-based warehouse UAT is activated or claimed.'
    assert_includes @authorization, 'PostgreSQL 17.10 and MySQL 8.4.11 evidence remains `READY / NOT RUN`'

    readme = File.binread(File.join(ROOT, 'docs/new-simrs-rebuild/phase-1/README.md'))
    assert_includes readme, 'Apotek is therefore an implemented local application module, not a global `Soon` placeholder'
    assert_includes readme, 'warehouse code is not included in that PASS'
    assert_includes readme, '`WAREHOUSE_SCHEMA_MIGRATION_ENABLED=false`'
  end
end
