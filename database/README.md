# ฐานข้อมูล RoietKPS

ระบบใช้ MySQL 5.7+ หรือ MariaDB 10.2+ แบบ InnoDB/utf8mb4 และรองรับ PHP 7.4 ให้นำเข้าไฟล์ตามลำดับและนำเข้าแต่ละไฟล์เพียงครั้งเดียว

1. `001_user_access.sql` — ผู้ใช้งาน บทบาท สิทธิ์ จังหวัด และทะเบียนหน่วยงาน
2. `002_registration_approval.sql` — สมัครสมาชิก การอนุมัติบัญชี และการจำกัดคำขอเข้าสู่ระบบ
3. `003_core_workflow.sql` — ข้อมูลหน่วยงาน เจ้าหน้าที่รับผิดชอบ คำขอกู้ เอกสาร การตรวจสอบ สัญญา การชำระ และประวัติการดำเนินการ
4. `004_provincial_office_registration.sql` — เพิ่มสำนักงานสหกรณ์จังหวัดร้อยเอ็ดในประเภทหน่วยงานของแบบฟอร์มสมัคร
5. `005_tracking_notifications.sql` — งานตามกระบวนการ กำหนดเวลา และศูนย์แจ้งเตือนภายในระบบ
6. `006_public_documents.sql` — คู่มือและเอกสารเผยแพร่สำหรับดาวน์โหลด
7. `007_public_document_pinning.sql` — การปักหมุดเอกสารสำคัญ
8. `008_loan_document_workflow.sql` — การส่ง แก้ไข และตรวจเอกสารประกอบคำขอกู้

## บทบาท

| รหัส | บทบาท | ขอบเขต |
| --- | --- | --- |
| `system_admin` | Admin | จัดการบัญชี ข้อมูลอ้างอิง ปลดล็อก และตรวจประวัติ |
| `provincial_officer` | เจ้าหน้าที่จังหวัด | ตรวจและอนุมัติคำขอในจังหวัด จัดทำสัญญา และติดตามการชำระ |
| `promotion_officer` | เจ้าหน้าที่ส่งเสริม | ดูหน่วยงานที่ได้รับมอบหมาย ตรวจเอกสาร และให้ความเห็น |
| `cooperative_user` | สหกรณ์/กลุ่มเกษตรกร | จัดการข้อมูลตนเอง สร้างคำขอ อัปโหลดเอกสาร และติดตามสัญญา |
| `central_officer` | เจ้าหน้าที่ส่วนกลาง | ดูข้อมูลและรายงานทุกพื้นที่ |
| `applicant` | ผู้สมัครรออนุมัติ | ไม่มีสิทธิ์ข้อมูลจนกว่า Admin จะอนุมัติ |

หนึ่งหน่วยงานมีผู้ใช้งานและคำขอกู้ได้หลายรายการ แต่ละคำขอมีผู้ค้ำ เอกสาร ความเห็น และประวัติสถานะแยกกัน เมื่อจังหวัดอนุมัติ ระบบจะสร้าง `loan_contracts` สถานะ `awaiting_preparation` โดยอัตโนมัติ

## กระบวนการหลัก

```text
สมัครสมาชิก → Admin อนุมัติและสร้าง/ผูกหน่วยงาน
             → หน่วยงานกรอกข้อมูลให้ครบ
             → สร้างคำขอกู้และผู้ค้ำ
             → เจ้าหน้าที่ส่งเสริมตรวจ/ส่งกลับแก้ไข
             → เจ้าหน้าที่จังหวัดตรวจและอนุมัติ
             → รอจัดทำสัญญา → ลงนาม → เบิกจ่าย → ติดตามชำระ → ปิดสัญญา
```

คำขอที่อนุมัติแล้วถูกล็อก การแก้ไขภายหลังต้องใช้สิทธิ์ `records.unlock` และบันทึกเหตุผลใน `audit_logs` ห้ามแก้ข้อมูลที่อนุมัติแล้วด้วย SQL โดยตรง

## ตารางสำคัญ

| ตาราง | หน้าที่ |
| --- | --- |
| `users`, `roles`, `permissions`, `role_permissions` | บัญชีและการควบคุมสิทธิ์ |
| `cooperatives` | สหกรณ์และกลุ่มเกษตรกร |
| `organization_officer_assignments` | ประวัติการมอบหมายเจ้าหน้าที่ส่งเสริม |
| `loan_applications` | คำขอกู้และสถานะการตรวจสอบ |
| `loan_guarantors` | ผู้ค้ำหลายคนต่อคำขอ |
| `organization_documents`, `loan_documents` | เอกสารระดับหน่วยงานและระดับคำขอ |
| `loan_document_reviews` | ประวัติผลการตรวจเอกสารประกอบคำขอแต่ละฉบับ |
| `loan_reviews` | ความเห็นและผลการตรวจแต่ละรอบ |
| `loan_contracts` | สัญญาที่สร้างจากคำขออนุมัติ |
| `repayment_schedules`, `repayments` | ตารางชำระและรายการรับชำระ |
| `audit_logs` | ประวัติการเปลี่ยนแปลงข้อมูลสำคัญ |
| `workflow_tasks`, `notification_rules` | งาน ผู้รับผิดชอบ กำหนดเวลา และกติกาการติดตาม |
| `notifications`, `notification_deliveries` | การแจ้งเตือนและสถานะการส่งแต่ละช่องทาง |
| `public_documents` | คู่มือ แบบฟอร์ม และเอกสารเผยแพร่สำหรับดาวน์โหลด |

## การนำเข้า

```sh
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/001_user_access.sql
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/002_registration_approval.sql
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/003_core_workflow.sql
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/004_provincial_office_registration.sql
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/005_tracking_notifications.sql
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/006_public_documents.sql
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/007_public_document_pinning.sql
mysql --default-character-set=utf8mb4 -h localhost -u roietkps_user -p roietkps_db < database/008_loan_document_workflow.sql
```

ฐานข้อมูลบนโฮสต์ปัจจุบันนำเข้าถึง `003_core_workflow.sql` แล้ว ตรวจสอบได้ด้วย:

```sql
SELECT code, name, data_scope FROM roles ORDER BY id;
SELECT COUNT(*) FROM permissions;
SHOW TABLES;
```

รหัสผ่านผู้ใช้งานเว็บต้องเก็บด้วย `password_hash()` เท่านั้น ห้ามเก็บรหัสผ่านจริงในฐานข้อมูล ไฟล์ SQL session หรือ log

## การทดสอบ

การทดสอบ schema เดิมใช้ฐานข้อมูลที่ชื่อขึ้นต้นด้วย `roietkps_test_` เท่านั้น:

```sh
TEST_DB_DSN='mysql:host=127.0.0.1;dbname=roietkps_test_access;charset=utf8mb4' \
TEST_DB_USER='test_user' php tests/user_access_schema_test.php
```

กระบวนการใน migration 003 ผ่านการทดสอบด้วย PHP 7.4 และ MariaDB ตั้งแต่สมัครและอนุมัติบัญชี สร้างหน่วยงาน สร้างคำขอพร้อมผู้ค้ำหลายคน ส่งตรวจสองระดับ อนุมัติ และสร้างรายการรอจัดทำสัญญา

## การประมวลผลแจ้งเตือนตามกำหนดเวลา

ระบบจะตรวจงานเมื่อผู้ใช้เข้าสู่ระบบ และสามารถตั้ง Cron ให้ตรวจทุก 15 นาทีเพื่อสร้างการแจ้งเตือนล่วงหน้า/เกินกำหนดได้:

```sh
*/15 * * * * /usr/bin/php /path/to/roietkps/tools/run-notifications.php
```

ให้ปรับ path ของ PHP และโครงการตามค่าจริงบนโฮสต์ คำสั่งนี้ไม่ต้องเปิดผ่านเว็บไซต์
