<?php
/**
 * MediCare - admin/specializations.php
 * CRUD for specializations. Deleting is allowed because
 * doctors.specialization_id uses ON DELETE SET NULL.
 */
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

// ---------- 1. POST handling ----------
if (is_post()) {
    verify_csrf('admin/specializations.php');
    $action = post('action');

    if ($action === 'create' || $action === 'update') {
        $id   = post_int('id');
        $name = post('specialization_name');
        $desc = null_if_blank(post('description'));

        if ($name === '') {
            flash_error('Please enter a specialization name.');
        } elseif (db_exists('SELECT 1 FROM specializations WHERE specialization_name = ? AND id <> ?', [$name, $id])) {
            flash_error('This specialization already exists.');
        } elseif ($action === 'create') {
            db_insert('INSERT INTO specializations (specialization_name, description) VALUES (?, ?)', [$name, $desc]);
            flash_success('Specialization added successfully.');
        } else {
            $affected = db_execute(
                'UPDATE specializations SET specialization_name = ?, description = ? WHERE id = ?',
                [$name, $desc, $id]
            );
            $affected ? flash_success('Specialization updated successfully.') : flash_error('Specialization not found.');
        }
    } elseif ($action === 'delete') {
        $id       = post_int('id');
        $affected = db_execute('DELETE FROM specializations WHERE id = ?', [$id]);
        $affected ? flash_success('Specialization deleted. Doctors using it are now unassigned from a specialization.')
                  : flash_error('Specialization not found.');
    }

    redirect('admin/specializations.php');
}

// ---------- 2. Read data ----------
$rows = db_all(
    "SELECT s.*, (SELECT COUNT(*) FROM doctors d WHERE d.specialization_id = s.id) AS doctor_count
       FROM specializations s
      ORDER BY s.specialization_name"
);

// ---------- 3. Render ----------
$page_title = 'Specializations';
$active     = 'specializations';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <div class="breadcrumbs"><a href="<?= e(url('admin/dashboard.php')) ?>">Dashboard</a> / Specializations</div>
    <h1>Specializations</h1>
    <div class="sub">Manage the list of medical specializations used by doctors.</div>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><?= icon('plus') ?> Add Specialization</button>
  </div>
</div>

<div class="table-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead><tr><th>#</th><th>Name</th><th>Description</th><th>Doctors</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5"><div class="empty"><?= icon('search') ?><div>No specializations found.</div></div></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><?= (int) $r['id'] ?></td>
          <td><?= e($r['specialization_name']) ?></td>
          <td><?= e(str_limit((string) $r['description'], 80)) ?></td>
          <td><?= (int) $r['doctor_count'] ?></td>
          <td class="text-end">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal" data-bs-target="#editModal"
                    data-id="<?= (int) $r['id'] ?>"
                    data-specialization-name="<?= e($r['specialization_name']) ?>"
                    data-description="<?= e($r['description']) ?>">
              <?= icon('edit') ?>
            </button>
            <form method="post" class="d-inline"
                  data-confirm="Delete '<?= e($r['specialization_name']) ?>'? <?= (int) $r['doctor_count'] ?> doctor(s) currently use it and will become unassigned from a specialization.">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><?= icon('trash') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Modal -->
<div class="modal" id="addModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="create">
   <div class="modal-header"><h5 class="modal-title">Add Specialization</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="specialization_name" required></div>
     <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3"></textarea></div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save</button></div>
  </form>
 </div></div>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal" tabindex="-1" aria-hidden="true" data-autofill>
 <div class="modal-dialog"><div class="modal-content">
  <form method="post">
   <?= csrf_field() ?><input type="hidden" name="action" value="update">
   <input type="hidden" name="id" data-fill="id">
   <div class="modal-header"><h5 class="modal-title">Edit Specialization</h5>
     <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
   <div class="modal-body">
     <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="specialization_name" data-fill="specializationName" required></div>
     <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="3" data-fill="description"></textarea></div>
   </div>
   <div class="modal-footer">
     <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
     <button class="btn btn-primary">Save Changes</button></div>
  </form>
 </div></div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
