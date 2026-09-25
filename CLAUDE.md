# 작업 규칙

- 기능을 추가하거나 고칠 때마다 `app/changelog.php`의 `CHANGELOG` 맨 위에 항목을 추가한다
  (기타 › 업데이트 화면에 표시됨). 날짜, 한 줄 요약, 관련 메뉴, 사용자 입장에서 알기 쉬운 설명 몇 줄.
- README.md 도 함께 갱신한다.
- DB 구조가 바뀌면 `sql/schema.sql` 과 `app/migrate.php`(DB_VERSION + 단계) 를 함께 고친다.
