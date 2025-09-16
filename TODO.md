# TODO: Migrate to Database and Add Features

## 1. Update pon_new.php
- [ ] Add qty field to form and validation
- [ ] Change job_type to select dropdown with options: pengadaan, pengiriman, pemasangan, konsultan
- [ ] Add more fields: client, nama_proyek, alamat_kontrak, no_contract, pic, owner
- [ ] Change saving logic to insert into DB using insert() function
- [ ] Add default tasks creation for new PON (4 tasks per division)

## 2. Update dashboard.php
- [ ] Change data loading from JSON to DB queries
- [ ] Update total_berat_kg calculation to berat * qty
- [ ] Update table to show Berat Satuan, QTY, Total Berat columns
- [ ] Update bar chart to sort by total weight

## 3. Update pon.php
- [ ] Change data loading from JSON to DB
- [ ] Update totalBerat calculation to berat * qty
- [ ] Update table columns: Berat Satuan, QTY, Total Berat

## 4. Update progres_divisi.php
- [ ] Change data loading to DB
- [ ] Update task updates to use update() function

## 5. Update tasklist.php
- [ ] Change data loading to DB
- [ ] Update task updates to use update() function

## 6. Testing
- [ ] Test adding new PON saves to DB
- [ ] Test dashboard displays correct data
- [ ] Test PON page displays correct data
- [ ] Test task updates work
