package com.reel.entity;

import jakarta.persistence.*;
import org.hibernate.annotations.JdbcTypeCode;
import org.hibernate.type.SqlTypes;

import java.time.LocalDateTime;
import java.util.Map;
import java.util.UUID;

/**
 * Spring Boot 3.x JPA Entity representing a 9:16 Video Reel Generation Project.
 * Maps PostgreSQL JSONB columns for Whisper word timestamps and Brand Kit settings.
 */
@Entity
@Table(name = "projects")
public class Project {

    @Id
    @GeneratedValue(strategy = GenerationType.UUID)
    private UUID id;

    @Column(nullable = false)
    private String title;

    @Column(columnDefinition = "TEXT")
    private String promptText;

    @Enumerated(EnumType.STRING)
    @Column(nullable = false)
    private ProjectStatus status = ProjectStatus.DRAFT;

    @Column(name = "video_style")
    private String videoStyle;

    @Column(name = "voice_id")
    private String voiceId;

    /**
     * Maps OpenAI Whisper word-level timestamps (start, end, text) in JSONB format.
     */
    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "whisper_timestamps", columnDefinition = "jsonb")
    private Map<String, Object> whisperTimestamps;

    /**
     * Maps user brand kit settings (watermark position, fonts, logo URL) in JSONB format.
     */
    @JdbcTypeCode(SqlTypes.JSON)
    @Column(name = "brand_kit_config", columnDefinition = "jsonb")
    private Map<String, Object> brandKitConfig;

    @Column(name = "export_url")
    private String exportUrl;

    @Column(name = "created_at", nullable = false, updatable = false)
    private LocalDateTime createdAt = LocalDateTime.now();

    @Column(name = "updated_at")
    private LocalDateTime updatedAt = LocalDateTime.now();

    // Getters and Setters
    public UUID getId() { return id; }
    public void setId(UUID id) { this.id = id; }

    public String getTitle() { return title; }
    public void setTitle(String title) { this.title = title; }

    public String getPromptText() { return promptText; }
    public void setPromptText(String promptText) { this.promptText = promptText; }

    public ProjectStatus getStatus() { return status; }
    public void setStatus(ProjectStatus status) { this.status = status; }

    public Map<String, Object> getWhisperTimestamps() { return whisperTimestamps; }
    public void setWhisperTimestamps(Map<String, Object> whisperTimestamps) { this.whisperTimestamps = whisperTimestamps; }

    public Map<String, Object> getBrandKitConfig() { return brandKitConfig; }
    public void setBrandKitConfig(Map<String, Object> brandKitConfig) { this.brandKitConfig = brandKitConfig; }

    public String getExportUrl() { return exportUrl; }
    public void setExportUrl(String exportUrl) { this.exportUrl = exportUrl; }
}

enum ProjectStatus {
    DRAFT,
    QUEUED,
    GENERATING_STORYBOARD,
    GENERATING_ASSETS,
    SYNTHESIZING_TTS,
    APPLYING_DUCKING,
    RENDERING_REMOTION,
    COMPLETED,
    FAILED
}
