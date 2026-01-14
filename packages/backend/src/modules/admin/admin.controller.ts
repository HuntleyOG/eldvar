import { Controller, Get, Put, Patch, Body, Param, Query, UseGuards, ParseIntPipe } from '@nestjs/common';
import { JwtAuthGuard } from '../auth/guards/jwt-auth.guard';
import { AdminGuard } from '../auth/guards/admin.guard';
import { AdminService } from './admin.service';
import { UserRole } from '@prisma/client';

@Controller('admin')
@UseGuards(JwtAuthGuard, AdminGuard)
export class AdminController {
  constructor(private readonly adminService: AdminService) {}

  // Get dashboard statistics
  @Get('stats')
  async getDashboardStats() {
    return await this.adminService.getDashboardStats();
  }

  // Get all users with pagination
  @Get('users')
  async getAllUsers(
    @Query('page', ParseIntPipe) page: number = 1,
    @Query('limit', ParseIntPipe) limit: number = 50,
  ) {
    return await this.adminService.getAllUsers(page, limit);
  }

  // Search users
  @Get('users/search')
  async searchUsers(@Query('q') query: string) {
    return await this.adminService.searchUsers(query);
  }

  // Get user by ID
  @Get('users/:id')
  async getUserById(@Param('id', ParseIntPipe) id: number) {
    return await this.adminService.getUserById(id);
  }

  // Update user role
  @Patch('users/:id/role')
  async updateUserRole(
    @Param('id', ParseIntPipe) id: number,
    @Body('role') role: UserRole,
  ) {
    return await this.adminService.updateUserRole(id, role);
  }

  // Update user stats
  @Patch('users/:id/stats')
  async updateUserStats(
    @Param('id', ParseIntPipe) id: number,
    @Body() data: {
      gold?: number;
      overallXp?: number;
      level?: number;
    },
  ) {
    return await this.adminService.updateUserStats(id, data);
  }

  // Toggle user ban
  @Patch('users/:id/ban')
  async toggleUserBan(@Param('id', ParseIntPipe) id: number) {
    return await this.adminService.toggleUserBan(id);
  }
}
