import unittest
from scripts.audit_library import audit

class LegalAuditTests(unittest.TestCase):
    def test_missing_sources_and_duplicate_article_labels(self):
        rows=[
            {"law_name":"نظام تجريبي.","article_number":"المادة الأولى","text":"المادة الأولى\nنص","source":"moj.gov.sa"},
            {"law_name":"نظام تجريبي","article_number":"المادة الأولى","text":"المادة الأولى\nنص آخر","source":"moj.gov.sa"},
        ]
        report=audit(rows)
        self.assertEqual(report["total_records"],2)
        self.assertEqual(report["legislation_names"],1)
        self.assertEqual(report["legislation"][0]["repeated_article_labels"],1)
        self.assertEqual(report["issue_counts"]["missing_direct_url"],2)

    def test_multiple_article_headings_and_empty_text(self):
        rows=[
            {"law_name":"لائحة تجريبية","article_number":"المادة الأولى","text":"المادة الأولى\nأ\nالمادة الثانية\nب","source":"moj.gov.sa"},
            {"law_name":"لائحة تجريبية","article_number":"المادة الثانية","text":"","source":""},
        ]
        report=audit(rows)
        self.assertEqual(report["issue_counts"]["multiple_article_headings"],1)
        self.assertEqual(report["issue_counts"]["missing_text"],1)
        self.assertEqual(report["issue_counts"]["missing_source"],1)

    def test_conflicting_and_exact_duplicate_texts(self):
        rows=[
            {"law_name":"نظام مثال","article_number":"المادة الأولى","text":"المادة الأولى نص أ","source":"moj.gov.sa"},
            {"law_name":"نظام مثال.","article_number":"المادة الأولى","text":"المادة الأولى نص ب","source":"moj.gov.sa"},
            {"law_name":"نظام مثال","article_number":"المادة الأولى","text":"المادة الأولى نص أ","source":"moj.gov.sa"},
        ]
        report=audit(rows)
        self.assertEqual(report["issue_counts"]["exact_duplicate_record"],1)
        self.assertEqual(len(report["conflicting_article_labels"]),1)
        self.assertEqual(report["conflicting_article_labels"][0]["distinct_texts"],2)

    def test_whitespace_normalization_detects_exact_duplicates(self):
        rows=[
            {"law_name":"نظام مثال","article_number":"المادة الأولى","text":"المادة الأولى  نص   واحد","source":"moj.gov.sa"},
            {"law_name":"نظام مثال","article_number":"المادة الأولى","text":"المادة الأولى نص واحد","source":"moj.gov.sa"},
        ]
        report=audit(rows)
        self.assertEqual(report["issue_counts"]["exact_duplicate_record"],1)
        self.assertEqual(report["conflicting_article_labels"],[])

    def test_blank_records_are_not_exact_duplicates(self):
        rows=[{"law_name":"","article_number":"","text":"","source":""} for _ in range(2)]
        report=audit(rows)
        self.assertNotIn("exact_duplicate_record",report["issue_counts"])

    def test_reject_non_record_data(self):
        with self.assertRaises(ValueError):
            audit([None])

if __name__=="__main__":
    unittest.main()
